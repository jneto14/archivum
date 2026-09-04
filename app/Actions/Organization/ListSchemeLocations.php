<?php

declare(strict_types=1);

namespace App\Actions\Organization;

use App\Models\OrganizationNode;
use App\Models\OrganizationScheme;
use Illuminate\Support\Collection;

class ListSchemeLocations
{
    public function __construct(private readonly CountFiledDocuments $countFiledDocuments) {}

    /**
     * List every leaf node of $scheme — the places a document can actually be filed —
     * with how full each one is, so a user can pick a location the suggestions did not
     * offer. Paths are built from a single load of the scheme's nodes rather than by
     * walking each leaf's ancestors one query at a time.
     *
     * @param OrganizationScheme $scheme The scheme whose locations are listed.
     *
     * @return array<int, array{id: string, value: string, path: string, documentsCount: int, capacity: int|null}> Every leaf location, ordered by path.
     */
    public function handle(OrganizationScheme $scheme): array
    {
        $leafLevel = $scheme->levels->sortByDesc('position')->first();

        if ($leafLevel === null) {
            return [];
        }

        $nodes = OrganizationNode::query()
            ->whereIn('level_id', $scheme->levels->pluck('id'))
            ->get()
            ->keyBy('id');

        $leaves = $nodes->filter(fn (OrganizationNode $node) => $node->level_id === $leafLevel->id);
        $counts = $this->countFiledDocuments->forNodes($leaves);

        return $leaves
            ->map(fn (OrganizationNode $leaf) => [
                'id' => $leaf->id,
                'value' => $leaf->value,
                'path' => $this->pathFor($leaf, $nodes),
                'documentsCount' => $counts[$leaf->id] ?? 0,
                'capacity' => $leafLevel->capacity,
            ])
            ->sortBy('path')
            ->values()
            ->all();
    }

    /**
     * Build a node's full path from an already-loaded set of the scheme's nodes.
     *
     * @param OrganizationNode $node The node to build the path of.
     * @param Collection<array-key, OrganizationNode> $nodes Every node of the scheme, keyed by id.
     *
     * @return string The node's value joined with its ancestors', root first.
     */
    private function pathFor(OrganizationNode $node, Collection $nodes): string
    {
        $segments = [$node->value];

        while ($node->parent_id !== null) {
            $parent = $nodes->get($node->parent_id);

            if ($parent === null) {
                break;
            }

            $node = $parent;
            array_unshift($segments, $node->value);
        }

        return implode('-', $segments);
    }
}
