<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspaces;

use App\Actions\Documents\PurgeAttachment;
use App\Actions\Documents\PurgeDocument;
use App\Actions\Documents\RestoreAttachment;
use App\Actions\Documents\RestoreDocument;
use App\Actions\Workspace\EmptyWorkspaceTrash;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TrashController extends Controller
{
    /**
     * Show what a workspace's trash is holding.
     *
     * Two lists rather than one, because the two are recovered differently: a
     * trashed document comes back with the attachments that went down with it,
     * while an attachment trashed on its own belongs to a document still in
     * the archive and is restored into it. Merging them into one list would
     * also show every cascaded attachment beside its document, which is noise
     * — those are listed under the document as a count.
     *
     * @param Request $request The incoming request, for the acting user.
     * @param Workspace $workspace The workspace whose trash is shown.
     *
     * @return Response The trash page.
     *
     * @throws AuthorizationException If the current user cannot view $workspace's documents.
     */
    public function index(Request $request, Workspace $workspace): Response
    {
        $this->authorize('viewAny', [Document::class, $workspace]);

        $documents = Document::onlyTrashed()
            ->where('workspace_id', $workspace->id)
            ->with('documentType')
            ->withCount(['attachments' => fn ($query) => $query->onlyTrashed()])
            ->latest('deleted_at')
            ->paginate(15, ['*'], 'documents')
            ->withQueryString();

        // Only the ones trashed on their own: an attachment that went down
        // with its document carries that document's timestamp and is already
        // represented by the row above.
        $attachments = DocumentAttachment::onlyTrashed()
            ->whereHas('document', fn (Builder $query) => $query->where('workspace_id', $workspace->id))
            ->with('document:id,title')
            ->latest('deleted_at')
            ->paginate(15, ['*'], 'attachments')
            ->withQueryString();

        return Inertia::render('workspace/trash', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'retentionDays' => (int) config('archivum.trash.retention_days'),
            'canPurge' => $workspace->isAdmin($request->user()),
            'documents' => [
                'data' => $documents->getCollection()->map(fn (Document $document) => [
                    'id' => $document->id,
                    'title' => $document->title,
                    'document_type' => $document->documentType?->name,
                    'attachments_count' => $document->attachments_count,
                    'deleted_at' => $document->deleted_at?->toIso8601String(),
                ])->all(),
                'meta' => ['current_page' => $documents->currentPage(), 'last_page' => $documents->lastPage(), 'total' => $documents->total()],
            ],
            'attachments' => [
                'data' => $attachments->getCollection()->map(fn (DocumentAttachment $attachment) => [
                    'id' => $attachment->id,
                    'filename' => $attachment->filename,
                    'size' => $attachment->size,
                    'document' => $attachment->document === null
                        ? null
                        : ['id' => $attachment->document->id, 'title' => $attachment->document->title],
                    'deleted_at' => $attachment->deleted_at?->toIso8601String(),
                ])->all(),
                'meta' => ['current_page' => $attachments->currentPage(), 'last_page' => $attachments->lastPage(), 'total' => $attachments->total()],
            ],
        ]);
    }

    /**
     * Take a document back out of the trash.
     *
     * @param Workspace $workspace The workspace the document belongs to.
     * @param string $document The trashed document's id.
     * @param RestoreDocument $action Restores the document and the attachments trashed with it.
     *
     * @return RedirectResponse Back to the trash page.
     *
     * @throws AuthorizationException If the current user cannot restore the document.
     */
    public function restoreDocument(Workspace $workspace, string $document, RestoreDocument $action): RedirectResponse
    {
        $trashed = $this->trashedDocument($workspace, $document);

        $this->authorize('restore', $trashed);

        $action->handle($trashed);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('document.restored')]);

        return back();
    }

    /**
     * Destroy a trashed document for good.
     *
     * @param Workspace $workspace The workspace the document belongs to.
     * @param string $document The trashed document's id.
     * @param PurgeDocument $action Unlinks the attachment files and destroys the records.
     *
     * @return RedirectResponse Back to the trash page.
     *
     * @throws AuthorizationException If the current user cannot destroy the document.
     */
    public function purgeDocument(Workspace $workspace, string $document, PurgeDocument $action): RedirectResponse
    {
        $trashed = $this->trashedDocument($workspace, $document);

        $this->authorize('forceDelete', $trashed);

        $action->handle($trashed);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('document.purged')]);

        return back();
    }

    /**
     * Take an attachment back out of the trash.
     *
     * @param Workspace $workspace The workspace the attachment's document belongs to.
     * @param string $attachment The trashed attachment's id.
     * @param RestoreAttachment $action Restores the attachment and re-indexes its document.
     *
     * @return RedirectResponse Back to the trash page.
     *
     * @throws AuthorizationException If the current user cannot restore the attachment.
     */
    public function restoreAttachment(Workspace $workspace, string $attachment, RestoreAttachment $action): RedirectResponse
    {
        $trashed = $this->trashedAttachment($workspace, $attachment);

        $this->authorize('restore', $trashed);

        $action->handle($trashed);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('document.attachment_restored')]);

        return back();
    }

    /**
     * Destroy a trashed attachment for good.
     *
     * @param Workspace $workspace The workspace the attachment's document belongs to.
     * @param string $attachment The trashed attachment's id.
     * @param PurgeAttachment $action Unlinks the stored file and destroys the record.
     *
     * @return RedirectResponse Back to the trash page.
     *
     * @throws AuthorizationException If the current user cannot destroy the attachment.
     */
    public function purgeAttachment(Workspace $workspace, string $attachment, PurgeAttachment $action): RedirectResponse
    {
        $trashed = $this->trashedAttachment($workspace, $attachment);

        $this->authorize('forceDelete', $trashed);

        $action->handle($trashed);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('document.attachment_purged')]);

        return back();
    }

    /**
     * Destroy everything in the workspace's trash.
     *
     * @param Workspace $workspace The workspace whose trash is emptied.
     * @param EmptyWorkspaceTrash $action Purges every trashed document and attachment.
     *
     * @return RedirectResponse Back to the trash page.
     *
     * @throws AuthorizationException If the current user does not administer $workspace.
     */
    public function empty(Workspace $workspace, EmptyWorkspaceTrash $action): RedirectResponse
    {
        $this->authorize('update', $workspace);

        $action->handle($workspace);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('document.trash_emptied')]);

        return back();
    }

    /**
     * Resolve a trashed document by id within the workspace.
     *
     * Route model binding cannot do this: its lookup runs through the model's
     * default scope, which excludes exactly the rows this controller exists to
     * act on. Scoping the query to the workspace here is also what stops an id
     * from another installation's workspace resolving at all.
     *
     * @param Workspace $workspace The workspace the document must belong to.
     * @param string $id The trashed document's id.
     *
     * @return Document The trashed document.
     */
    private function trashedDocument(Workspace $workspace, string $id): Document
    {
        return Document::onlyTrashed()
            ->where('workspace_id', $workspace->id)
            ->findOrFail($id);
    }

    /**
     * Resolve a trashed attachment by id within the workspace.
     *
     * @param Workspace $workspace The workspace the attachment's document must belong to.
     * @param string $id The trashed attachment's id.
     *
     * @return DocumentAttachment The trashed attachment.
     */
    private function trashedAttachment(Workspace $workspace, string $id): DocumentAttachment
    {
        return DocumentAttachment::onlyTrashed()
            ->whereIn(
                'document_id',
                Document::withTrashed()->where('workspace_id', $workspace->id)->select('id'),
            )
            ->findOrFail($id);
    }
}
