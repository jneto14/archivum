<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Documents\PurgeAttachment;
use App\Actions\Documents\PurgeDocument;
use App\Actions\Documents\RestoreAttachment;
use App\Actions\Documents\RestoreDocument;
use App\Actions\Workspace\EmptyWorkspaceTrash;
use App\Concerns\ResolvesWorkspaceRecords;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AttachmentResource;
use App\Http\Resources\Api\V1\DocumentResource;
use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\Workspace;
use App\Support\PageSize;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * What is in the trash, and the two ways out of it.
 *
 * Two listings rather than the interface's single page holding both, because
 * a page can carry two paginators side by side and a response cannot say which
 * `meta` belongs to which half.
 *
 * Everything here resolves its id with `onlyTrashed()` rather than through
 * route model binding, which runs the ordinary lookup and would 404 on every
 * one of these.
 */
class TrashController extends Controller
{
    use ResolvesWorkspaceRecords;

    /**
     * List the documents in the workspace's trash, most recently trashed first.
     *
     * @param Request $request The incoming request, read for the page size.
     * @param Workspace $workspace The workspace whose trash is listed.
     *
     * @return AnonymousResourceCollection A page of trashed documents, with the retention window in meta.
     *
     * @throws AuthorizationException If the token's user isn't a member of $workspace.
     */
    public function documents(Request $request, Workspace $workspace): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [Document::class, $workspace]);

        $documents = Document::onlyTrashed()
            ->where('workspace_id', $workspace->id)
            ->with('documentType')
            ->latest('deleted_at')
            ->paginate(PageSize::fromRequest($request))
            ->withQueryString();

        return DocumentResource::collection($documents)->additional([
            'meta' => ['retention_days' => (int) config('archivum.trash.retention_days')],
        ]);
    }

    /**
     * List the attachments in the workspace's trash, most recently trashed first.
     *
     * Includes the ones that went down with their document, unlike the
     * interface's list, which hides those because the document's own row
     * already stands for them. A client walking the trash has no such row to
     * read and would otherwise be told a file it can see is not there.
     *
     * @param Request $request The incoming request, read for the page size.
     * @param Workspace $workspace The workspace whose trash is listed.
     *
     * @return AnonymousResourceCollection A page of trashed attachments, with the retention window in meta.
     *
     * @throws AuthorizationException If the token's user isn't a member of $workspace.
     */
    public function attachments(Request $request, Workspace $workspace): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [Document::class, $workspace]);

        $attachments = DocumentAttachment::onlyTrashed()
            ->whereIn(
                'document_id',
                Document::withTrashed()->where('workspace_id', $workspace->id)->select('id'),
            )
            // The document's soft-delete scope is lifted, because an
            // attachment in the trash very often went there with its document
            // and the scope would otherwise leave the row naming nothing.
            // Reached through the relation's own query: `withTrashed()` is a
            // builder scope, and a relation only forwards it by __call.
            ->with([
                'document' => fn (Relation $document) => $document->getQuery()
                    ->withoutGlobalScope(SoftDeletingScope::class),
            ])
            ->latest('deleted_at')
            ->paginate(PageSize::fromRequest($request))
            ->withQueryString();

        $collection = AttachmentResource::collection($attachments);

        // Opt every row into naming its document. `collection()` offers no
        // per-item hook, and the document is the whole reason the eager load
        // above lifts the soft-delete scope: a filename with nothing beside it
        // does not tell a client what it is about to lose.
        $collection->collection->each(
            fn (AttachmentResource $resource) => $resource->withDocument(),
        );

        return $collection->additional([
            'meta' => ['retention_days' => (int) config('archivum.trash.retention_days')],
        ]);
    }

    /**
     * Take a document back out of the trash, along with the attachments
     * trashed with it.
     *
     * @param Workspace $workspace The workspace the document belongs to.
     * @param string $document The trashed document's id.
     * @param RestoreDocument $action Restores the document and its attachments.
     *
     * @return DocumentResource The restored document.
     *
     * @throws AuthorizationException If the token's user cannot restore $document.
     * @throws ModelNotFoundException If no trashed document with that id exists in $workspace.
     */
    public function restoreDocument(Workspace $workspace, string $document, RestoreDocument $action): DocumentResource
    {
        $trashed = $this->trashedDocument($workspace, $document);

        $this->authorize('restore', $trashed);

        $action->handle($trashed);

        return new DocumentResource($trashed->refresh()->load(['documentType', 'tags', 'creator']));
    }

    /**
     * Destroy a trashed document for good, with its attachments and their files.
     *
     * Narrower than trashing it: trashing is reversible and this is not, so it
     * is the workspace's administrators who carry it.
     *
     * @param Workspace $workspace The workspace the document belongs to.
     * @param string $document The trashed document's id.
     * @param PurgeDocument $action Unlinks the files and deletes the rows.
     *
     * @return JsonResponse An empty 204.
     *
     * @throws AuthorizationException If the token's user cannot destroy $document.
     * @throws ModelNotFoundException If no trashed document with that id exists in $workspace.
     */
    public function purgeDocument(Workspace $workspace, string $document, PurgeDocument $action): JsonResponse
    {
        $trashed = $this->trashedDocument($workspace, $document);

        $this->authorize('forceDelete', $trashed);

        $action->handle($trashed);

        return new JsonResponse(status: 204);
    }

    /**
     * Take an attachment back out of the trash.
     *
     * @param Workspace $workspace The workspace the attachment's document belongs to.
     * @param string $attachment The trashed attachment's id.
     * @param RestoreAttachment $action Restores the attachment and re-indexes its document.
     *
     * @return AttachmentResource The restored attachment.
     *
     * @throws AuthorizationException If the token's user cannot restore $attachment.
     * @throws ModelNotFoundException If no trashed attachment with that id exists in $workspace.
     */
    public function restoreAttachment(Workspace $workspace, string $attachment, RestoreAttachment $action): AttachmentResource
    {
        $trashed = $this->trashedAttachment($workspace, $attachment);

        $this->authorize('restore', $trashed);

        $action->handle($trashed);

        return new AttachmentResource($trashed->refresh());
    }

    /**
     * Destroy a trashed attachment for good, and unlink every file it holds or
     * has held.
     *
     * @param Workspace $workspace The workspace the attachment's document belongs to.
     * @param string $attachment The trashed attachment's id.
     * @param PurgeAttachment $action Unlinks the chain and deletes the row.
     *
     * @return JsonResponse An empty 204.
     *
     * @throws AuthorizationException If the token's user cannot destroy $attachment.
     * @throws ModelNotFoundException If no trashed attachment with that id exists in $workspace.
     */
    public function purgeAttachment(Workspace $workspace, string $attachment, PurgeAttachment $action): JsonResponse
    {
        $trashed = $this->trashedAttachment($workspace, $attachment);

        $this->authorize('forceDelete', $trashed);

        $action->handle($trashed);

        return new JsonResponse(status: 204);
    }

    /**
     * Empty the workspace's trash.
     *
     * @param Workspace $workspace The workspace whose trash is emptied.
     * @param EmptyWorkspaceTrash $action Purges every trashed document and attachment.
     *
     * @return JsonResponse An empty 204.
     *
     * @throws AuthorizationException If the token's user cannot administer $workspace.
     */
    public function empty(Workspace $workspace, EmptyWorkspaceTrash $action): JsonResponse
    {
        $this->authorize('update', $workspace);

        $action->handle($workspace);

        return new JsonResponse(status: 204);
    }
}
