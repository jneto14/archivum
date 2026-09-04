<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\OrganizationLevel;
use App\Models\OrganizationNode;
use App\Models\OrganizationScheme;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationStorageController extends Controller
{
    /** How many of a location's documents the panel lists before deferring to the documents index. */
    private const int NODE_DOCUMENTS_SHOWN = 20;

    /**
     * Browse a scheme's physical node tree.
     *
     * @param Request $request The incoming request, used to resolve the acting user.
     * @param OrganizationScheme $scheme The scheme whose nodes are browsed.
     *
     * @return Response The rendered physical storage page.
     *
     * @throws AuthorizationException If the current user cannot view $scheme.
     */
    public function show(Request $request, OrganizationScheme $scheme): Response
    {
        $this->authorize('view', $scheme);

        $scheme->load(['levels' => fn ($query) => $query->orderBy('position')->with('nodes')]);

        return Inertia::render('organization/storage', [
            'scheme' => ['id' => $scheme->id, 'name' => $scheme->name],
            'levels' => $scheme->levels->map(fn ($level) => [
                'id' => $level->id,
                'name' => $level->name,
                'position' => $level->position,
                'capacity' => $level->capacity,
                'has_printable_label' => $level->has_printable_label,
                'value_strategy' => $level->value_strategy->value,
                'is_leaf' => $level->isLeaf(),
            ])->values()->all(),
            'tree' => $this->buildTree($scheme->levels),
            'canManage' => $scheme->workspace->isManageableBy($request->user()),
            // Only the location named by `?node=`, and null without one: the
            // panel fetches it by partial reload when a location is opened, and
            // a label's QR code lands here with it already in the URL.
            'nodeDocuments' => $this->nodeDocuments($scheme, $request->query('node')),
        ]);
    }

    /**
     * The documents currently filed at one of the scheme's nodes, capped: a location
     * holding hundreds is answered with the first page and a count, and the documents
     * index — filtered by the same node — is where the rest lives.
     *
     * @param OrganizationScheme $scheme The scheme the node must belong to.
     * @param mixed $nodeId The requested node id, straight off the query string.
     *
     * @return array{node: array{id: string, path: string}, documents: array<int, array{id: string, title: string, document_type: string|null, document_date: string|null}>, total: int}|null The node's contents, or null if no node was asked for or it is not this scheme's.
     */
    private function nodeDocuments(OrganizationScheme $scheme, mixed $nodeId): ?array
    {
        if (!is_string($nodeId)) {
            return null;
        }

        $node = OrganizationNode::query()
            ->whereHas('level', fn ($query) => $query->where('scheme_id', $scheme->id))
            ->find($nodeId);

        if ($node === null) {
            return null;
        }

        $documents = Document::query()
            ->whereHas('currentLocation', fn ($query) => $query->where('organization_node_id', $node->id))
            ->with('documentType')
            ->orderBy('title');

        return [
            'node' => ['id' => $node->id, 'path' => $node->path()],
            'total' => $documents->clone()->count(),
            'documents' => $documents
                ->limit(self::NODE_DOCUMENTS_SHOWN)
                ->get()
                ->map(fn (Document $document) => [
                    'id' => $document->id,
                    'title' => $document->title,
                    'document_type' => $document->documentType?->name,
                    'document_date' => $document->document_date?->toDateString(),
                ])
                ->all(),
        ];
    }

    /**
     * Build the node tree for the given ordered levels, nesting each level's
     * nodes under their parent from the level above.
     *
     * @param Collection<int, OrganizationLevel> $levels The scheme's levels, in position order, each with its `nodes` relation already eager-loaded.
     * @param string|null $parentId The parent node id to build children for, or null for the root level.
     *
     * @return array<int, array{id: string, value: string, path: string, documents_count: int|null, children: array<int, mixed>}> The nested node tree.
     */
    private function buildTree(Collection $levels, ?string $parentId = null): array
    {
        $level = $levels->first();

        if ($level === null) {
            return [];
        }

        $remaining = $levels->slice(1);
        $isLeaf = $remaining->isEmpty();

        return $level->nodes
            ->filter(fn (OrganizationNode $node) => $node->parent_id === $parentId)
            ->map(fn (OrganizationNode $node) => [
                'id' => $node->id,
                'value' => $node->value,
                'path' => $node->path(),
                'documents_count' => $isLeaf ? $this->documentsAtNode($node) : null,
                'children' => $isLeaf ? [] : $this->buildTree($remaining, $node->id),
            ])
            ->values()
            ->all();
    }

    /**
     * Count documents whose current location is the given node.
     *
     * @param OrganizationNode $node The node to count filed documents at.
     *
     * @return int The number of documents currently located at $node.
     */
    private function documentsAtNode(OrganizationNode $node): int
    {
        return Document::query()
            ->whereHas('currentLocation', fn ($query) => $query->where('organization_node_id', $node->id))
            ->count();
    }
}
