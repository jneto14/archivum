<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Organization\CreateOrganizationNode;
use App\Actions\Organization\DeleteOrganizationNode;
use App\Actions\Organization\StartBulkDocumentMove;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\MigrateNodeDocumentsRequest;
use App\Http\Requests\Organization\StoreOrganizationNodeRequest;
use App\Http\Resources\Api\V1\DocumentResource;
use App\Http\Resources\Api\V1\OrganizationNodeResource;
use App\Models\Document;
use App\Models\OrganizationNode;
use App\Models\OrganizationScheme;
use App\Support\PageSize;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class OrganizationNodeController extends Controller
{
    /**
     * Browse a scheme's nodes, one tier at a time.
     *
     * `parent_id` walks down from the root, which is how the archive is
     * actually read: a client opening a cover wants what is in that cover, not
     * every shelf in the building flattened into one answer. Without it, the
     * root level's nodes come back.
     *
     * @param Request $request The incoming request, read for `parent_id` and the page size.
     * @param OrganizationScheme $scheme The scheme whose nodes are browsed.
     *
     * @return AnonymousResourceCollection A page of nodes at the requested tier.
     *
     * @throws AuthorizationException If the token's user cannot view $scheme.
     */
    public function index(Request $request, OrganizationScheme $scheme): AnonymousResourceCollection
    {
        $this->authorize('view', $scheme);

        $parentId = $request->query('parent_id');

        $nodes = OrganizationNode::query()
            ->whereHas('level', fn (Builder $level) => $level->where('scheme_id', $scheme->id))
            ->when(
                is_string($parentId),
                fn (Builder $query) => $query->where('parent_id', $parentId),
                fn (Builder $query) => $query->whereNull('parent_id'),
            )
            ->with('level')
            ->withCount('children')
            ->orderBy('value')
            ->paginate(PageSize::fromRequest($request))
            ->withQueryString();

        return OrganizationNodeResource::collection($nodes);
    }

    /**
     * List the documents currently filed at a node.
     *
     * Where they are now, not where they have been: a document moved on does
     * not come back here.
     *
     * @param Request $request The incoming request, read for the page size.
     * @param OrganizationNode $node The node whose contents are listed.
     *
     * @return AnonymousResourceCollection A page of documents at $node.
     *
     * @throws AuthorizationException If the token's user cannot view the node's scheme.
     */
    public function documents(Request $request, OrganizationNode $node): AnonymousResourceCollection
    {
        $this->authorize('view', $node->level->scheme);

        $documents = Document::query()
            ->whereHas('currentLocation', fn (Builder $query) => $query->where('organization_node_id', $node->id))
            ->with(['documentType', 'tags'])
            ->orderBy('title')
            ->paginate(PageSize::fromRequest($request))
            ->withQueryString();

        return DocumentResource::collection($documents);
    }

    /**
     * Create a node at one of the scheme's levels.
     *
     * @param StoreOrganizationNodeRequest $request The incoming request with the validated level, parent and value.
     * @param OrganizationScheme $scheme The scheme the node belongs to.
     * @param CreateOrganizationNode $action Creates the node, allocating its value where the level says so.
     *
     * @return JsonResponse The created node, with status 201.
     *
     * @throws AuthorizationException If the token's user cannot update $scheme.
     * @throws ModelNotFoundException If the level or parent does not belong to $scheme.
     * @throws ValidationException If the level needs a value and none was given.
     */
    public function store(StoreOrganizationNodeRequest $request, OrganizationScheme $scheme, CreateOrganizationNode $action): JsonResponse
    {
        $this->authorize('update', $scheme);

        $level = $scheme->levels()->where('id', $request->validated('level_id'))->firstOrFail();

        $parentId = $request->validated('parent_id');
        $parent = $parentId !== null
            ? OrganizationNode::query()
                ->whereHas('level', fn (Builder $query) => $query->where('scheme_id', $scheme->id))
                ->where('id', $parentId)
                ->firstOrFail()
            : null;

        $node = $action->handle($level, $parent, $request->validated('value'));

        return (new OrganizationNodeResource($node->load('level')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Delete a node.
     *
     * @param OrganizationScheme $scheme The scheme the node is expected to belong to.
     * @param OrganizationNode $node The node to delete.
     * @param DeleteOrganizationNode $action Deletes the node.
     *
     * @return JsonResponse An empty 204.
     *
     * @throws AuthorizationException If the token's user cannot update $scheme.
     * @throws NotFoundHttpException If $node does not belong to $scheme.
     * @throws ValidationException If the node still holds children or documents.
     */
    public function destroy(OrganizationScheme $scheme, OrganizationNode $node, DeleteOrganizationNode $action): JsonResponse
    {
        $this->authorize('update', $scheme);

        abort_unless($node->level->scheme_id === $scheme->id, 404);

        $action->handle($node);

        return new JsonResponse(status: 204);
    }

    /**
     * Move everything filed at a node to another one.
     *
     * Queued rather than done here: a full cover is a lot of documents, and
     * the Tasks endpoints are where its progress is read.
     *
     * @param MigrateNodeDocumentsRequest $request The incoming request with the validated target node.
     * @param OrganizationNode $node The node being emptied.
     * @param StartBulkDocumentMove $action Queues the move.
     *
     * @return JsonResponse An empty 202 — the work is queued, not finished.
     *
     * @throws AuthorizationException If the token's user cannot update the node's scheme.
     * @throws ModelNotFoundException If the target node does not belong to the same scheme.
     * @throws ValidationException If the target is $node itself, or a bulk move is already running for the workspace.
     */
    public function migrate(MigrateNodeDocumentsRequest $request, OrganizationNode $node, StartBulkDocumentMove $action): JsonResponse
    {
        $this->authorize('update', $node->level->scheme);

        $target = OrganizationNode::query()
            ->whereHas('level', fn (Builder $query) => $query->where('scheme_id', $node->level->scheme_id))
            ->where('id', $request->validated('target_node_id'))
            ->firstOrFail();

        $action->handle($node, $target, $request->user());

        return new JsonResponse(status: 202);
    }
}
