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
    public function __construct(
        private readonly FindAvailableLocation $findAvailableLocation,
        private readonly CountFiledDocuments $countFiledDocuments,
    ) {}

    /**
     * Suggest candidate leaf OrganizationNodes to file $document into within $scheme:
     * the top pick is whatever FindAvailableLocation would auto-resolve (marked
     * "recommended"), followed by up to $limit - 1 existing leaf nodes that still have
     * room, ranked by how empty they are, so a human can choose to reuse an existing
     * position instead of always creating a new one. If the leaf level is entirely at
     * capacity, FindAvailableLocation cannot resolve a node to recommend — that failure
     * is swallowed here and the suggestions fall back to existing nodes only.
     *
     * Suggesting is a read: when the recommendation is a location that does not exist
     * yet, it comes back with a null id and is only created once the user picks it.
     *
     * Where the document already sits is never suggested. For a document filed by the
     * rules, that is exactly where FindAvailableLocation resolves to, and offering to
     * move it there is offering to do nothing.
     *
     * @param Document $document The document being filed; its document type feeds the rule-matching criteria.
     * @param OrganizationScheme $scheme The scheme to suggest a leaf-level position within.
     * @param int $limit Maximum number of suggestions to return, including the recommended one.
     *
     * @return array<int, array{node: array{id: string|null, value: string, path: string}, documentsCount: int, capacity: int|null, recommended: bool}> Ordered suggestions, recommended first when available.
     *
     * @throws LogicException If $scheme has no levels defined.
     */
    public function handle(Document $document, OrganizationScheme $scheme, int $limit = 4): array
    {
        $leafLevel = $scheme->levels->sortByDesc('position')->first();

        if ($leafLevel === null) {
            throw new LogicException('Cannot suggest a location for a scheme with no levels.');
        }

        $currentNodeId = $document->currentLocation?->organization_node_id;

        $recommended = $this->recommend($document, $scheme);
        $recommendedNode = $recommended['node'] ?? null;

        if ($recommendedNode !== null && $recommendedNode->id === $currentNodeId) {
            $recommended = null;
            $recommendedNode = null;
        }

        $alternatives = OrganizationNode::query()
            ->where('level_id', $leafLevel->id)
            ->when($recommendedNode !== null, fn ($query) => $query->whereKeyNot($recommendedNode->id))
            ->when($currentNodeId !== null, fn ($query) => $query->whereKeyNot($currentNodeId))
            ->get();

        $counts = $this->countFiledDocuments->forNodes(
            $recommendedNode !== null ? [...$alternatives->all(), $recommendedNode] : $alternatives->all(),
        );

        $alternatives = $alternatives
            ->map(fn (OrganizationNode $node) => [
                'node' => $node,
                'documentsCount' => $counts[$node->id] ?? 0,
            ])
            ->when(
                $leafLevel->capacity !== null,
                fn (Collection $candidates) => $candidates->filter(fn (array $candidate) => $candidate['documentsCount'] < $leafLevel->capacity),
            )
            ->sortBy('documentsCount')
            ->take(max($limit - ($recommended !== null ? 1 : 0), 0));

        return [
            ...($recommended !== null ? [[
                'node' => [
                    'id' => $recommendedNode?->id,
                    'value' => $recommended['value'],
                    'path' => $recommended['path'],
                ],
                'documentsCount' => $recommendedNode !== null ? ($counts[$recommendedNode->id] ?? 0) : 0,
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
     * Ask FindAvailableLocation where $document would be filed, without creating anything,
     * treating its failure to resolve a location (e.g. the leaf level is entirely at
     * capacity) as "no recommendation" rather than propagating the exception.
     *
     * @param Document $document The document being filed; its document type feeds the rule-matching criteria.
     * @param OrganizationScheme $scheme The scheme to resolve a leaf-level position within.
     *
     * @return array{node: OrganizationNode|null, value: string, path: string}|null The resolved location, or null if none could be resolved.
     */
    private function recommend(Document $document, OrganizationScheme $scheme): ?array
    {
        try {
            return $this->findAvailableLocation->preview($scheme, ['document_type' => $document->documentType->key]);
        } catch (ValidationException) {
            return null;
        }
    }
}
