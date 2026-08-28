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
                'is_leaf' => $level->isLeaf(),
            ])->values()->all(),
            'tree' => $this->buildTree($scheme->levels),
            'canManage' => $scheme->workspace->isManageableBy($request->user()),
        ]);
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
