<?php

declare(strict_types=1);

namespace App\Actions\Organization;

use App\Models\Document;
use App\Models\OrganizationNode;
use App\Models\OrganizationScheme;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use LogicException;

class SuggestDocumentLocations
{
    public function __construct(private readonly FindAvailableLocation $findAvailableLocation) {}

    /**
     * Suggest candidate leaf OrganizationNodes to file $document into within $scheme:
     * the top pick is whatever FindAvailableLocation would auto-resolve (marked
     * "recommended"), followed by up to $limit - 1 existing leaf nodes that still have
     * room, ranked by how empty they are, so a human can choose to reuse an existing
     * position instead of always creating a new one. If the leaf level is entirely at
     * capacity, FindAvailableLocation cannot create a fresh node to recommend — that
     * failure is swallowed here and the suggestions fall back to existing nodes only.
     *
     * @param Document $document The document being filed; its document type feeds the rule-matching criteria.
     * @param OrganizationScheme $scheme The scheme to suggest a leaf-level position within.
     * @param int $limit Maximum number of suggestions to return, including the recommended one.
     *
     * @return array<int, array{node: array{id: string, value: string, path: string}, documentsCount: int, capacity: int|null, recommended: bool}> Ordered suggestions, recommended first when available.
     *
     * @throws LogicException If $scheme has no levels defined.
     */
    public function handle(Document $document, OrganizationScheme $scheme, int $limit = 4): array
    {
        $leafLevel = $scheme->levels->sortByDesc('position')->first();

        if ($leafLevel === null) {
            throw new LogicException('Cannot suggest a location for a scheme with no levels.');
        }

        $recommended = $this->recommend($document, $scheme);

        $alternatives = OrganizationNode::query()
            ->where('level_id', $leafLevel->id)
            ->when($recommended !== null, fn ($query) => $query->whereKeyNot($recommended->id))
            ->get()
            ->map(fn (OrganizationNode $node) => [
                'node' => $node,
                'documentsCount' => $this->documentsCount($node),
            ])
            ->when(
                $leafLevel->capacity !== null,
                fn (Collection $candidates) => $candidates->filter(fn (array $candidate) => $candidate['documentsCount'] < $leafLevel->capacity),
            )
            ->sortBy('documentsCount')
            ->take(max($limit - ($recommended !== null ? 1 : 0), 0));

        return [
            ...($recommended !== null ? [[
                'node' => $this->nodeArray($recommended),
                'documentsCount' => $this->documentsCount($recommended),
                'capacity' => $leafLevel->capacity,
                'recommended' => true,
            ]] : []),
            ...$alternatives
                ->map(fn (array $candidate) => [
                    'node' => $this->nodeArray($candidate['node']),
                    'documentsCount' => $candidate['documentsCount'],
                    'capacity' => $leafLevel->capacity,
                    'recommended' => false,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param OrganizationNode $node The node to shape for the frontend.
     *
     * @return array{id: string, value: string, path: string} The node's id, own value, and full hierarchical path.
     */
    private function nodeArray(OrganizationNode $node): array
    {
        return [
            'id' => $node->id,
            'value' => $node->value,
            'path' => $node->path(),
        ];
    }

    /**
     * Ask FindAvailableLocation to auto-resolve a leaf node for $document, treating its
     * failure to do so (e.g. the leaf level is entirely at capacity) as "no recommendation"
     * rather than propagating the exception.
     *
     * @param Document $document The document being filed; its document type feeds the rule-matching criteria.
     * @param OrganizationScheme $scheme The scheme to resolve a leaf-level position within.
     *
     * @return OrganizationNode|null The auto-resolved node, or null if none could be resolved/created.
     */
    private function recommend(Document $document, OrganizationScheme $scheme): ?OrganizationNode
    {
        try {
            return $this->findAvailableLocation->handle($scheme, ['document_type' => $document->documentType->key]);
        } catch (ValidationException) {
            return null;
        }
    }

    /**
     * Count documents whose current (latest) location is $node.
     *
     * @param OrganizationNode $node The node to count current placements for.
     *
     * @return int The number of documents currently filed at $node.
     */
    private function documentsCount(OrganizationNode $node): int
    {
        return Document::query()
            ->whereHas('currentLocation', fn ($query) => $query->where('organization_node_id', $node->id))
            ->count();
    }
}
