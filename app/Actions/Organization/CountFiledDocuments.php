<?php

declare(strict_types=1);

namespace App\Actions\Organization;

use App\Models\Document;
use App\Models\OrganizationNode;
use Illuminate\Support\Collection;

class CountFiledDocuments
{
    /**
     * Count the documents currently filed at each of the given nodes, in one pass
     * rather than a query per node. A node nothing is filed at is absent from the
     * result, so callers should treat a missing key as zero.
     *
     * @param Collection<array-key, OrganizationNode>|array<int, OrganizationNode> $nodes The nodes to count current placements for.
     *
     * @return array<string, int> Node id => number of documents currently filed there.
     */
    public function forNodes(Collection|array $nodes): array
    {
        $nodeIds = Collection::make($nodes)->pluck('id')->all();

        if ($nodeIds === []) {
            return [];
        }

        $documents = Document::query()
            ->whereHas('currentLocation', fn ($query) => $query->whereIn('organization_node_id', $nodeIds))
            ->with('currentLocation')
            ->get();

        $counts = [];

        foreach ($documents as $document) {
            $nodeId = $document->currentLocation?->organization_node_id;

            if ($nodeId !== null) {
                $counts[$nodeId] = ($counts[$nodeId] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Count the documents currently filed at a single node.
     *
     * @param OrganizationNode $node The node to count current placements for.
     *
     * @return int The number of documents currently filed at $node.
     */
    public function at(OrganizationNode $node): int
    {
        return $this->forNodes([$node])[$node->id] ?? 0;
    }
}
