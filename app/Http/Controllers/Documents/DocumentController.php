<?php

namespace App\Http\Controllers\Documents;

use App\Actions\Documents\CreateDocument;
use App\Actions\Documents\DeleteDocument;
use App\Actions\Documents\UpdateDocument;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\StoreDocumentRequest;
use App\Http\Requests\Documents\UpdateDocumentRequest;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Tag;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class DocumentController extends Controller
{
    /**
     * Create a new document within the given workspace.
     *
     * @param  StoreDocumentRequest  $request  The incoming request with the validated document attributes.
     * @param  Workspace  $workspace  The workspace the document is created in.
     * @param  CreateDocument  $action  Creates the document and syncs its tags.
     * @return RedirectResponse Redirect back to the previous page.
     *
     * @throws AuthorizationException If the current user cannot create documents in $workspace.
     * @throws ModelNotFoundException If the requested document type does not belong to $workspace.
     * @throws ValidationException If the workspace's document limit would be exceeded.
     */
    public function store(StoreDocumentRequest $request, Workspace $workspace, CreateDocument $action): RedirectResponse
    {
        $this->authorize('create', [Document::class, $workspace]);

        $type = $this->scopedDocumentType($workspace, $request->validated('document_type_id'));

        $action->handle(
            $workspace,
            $request->user(),
            $type,
            $request->validated('title'),
            $request->validated('document_date'),
            $request->validated('metadata'),
            $this->scopedTagIds($workspace, $request->validated('tag_ids') ?? []),
        );

        return back();
    }

    /**
     * Update a document's attributes and tags.
     *
     * @param  UpdateDocumentRequest  $request  The incoming request with the validated document attributes.
     * @param  Document  $document  The document being updated.
     * @param  UpdateDocument  $action  Applies the update and resyncs tags.
     * @return RedirectResponse Redirect back to the previous page.
     *
     * @throws AuthorizationException If the current user cannot update $document.
     * @throws ModelNotFoundException If the requested document type does not belong to the document's workspace.
     */
    public function update(UpdateDocumentRequest $request, Document $document, UpdateDocument $action): RedirectResponse
    {
        $this->authorize('update', $document);

        $type = $this->scopedDocumentType($document->workspace, $request->validated('document_type_id'));

        $action->handle(
            $document,
            $type,
            $request->validated('title'),
            $request->validated('document_date'),
            $request->validated('metadata'),
            $this->scopedTagIds($document->workspace, $request->validated('tag_ids') ?? []),
        );

        return back();
    }

    /**
     * Delete a document.
     *
     * @param  Document  $document  The document to delete.
     * @param  DeleteDocument  $action  Deletes the document and its cascading records.
     * @return RedirectResponse Redirect back to the previous page.
     *
     * @throws AuthorizationException If the current user cannot delete $document.
     */
    public function destroy(Document $document, DeleteDocument $action): RedirectResponse
    {
        $this->authorize('delete', $document);

        $action->handle($document);

        return back();
    }

    /**
     * Resolve a document type by id, scoped to the given workspace.
     *
     * @param  Workspace  $workspace  The workspace the document type must belong to.
     * @param  string  $documentTypeId  The UUID of the document type to resolve.
     * @return DocumentType The matching document type.
     *
     * @throws ModelNotFoundException If no document type with $documentTypeId exists in $workspace.
     */
    private function scopedDocumentType(Workspace $workspace, string $documentTypeId): DocumentType
    {
        return DocumentType::query()
            ->where('workspace_id', $workspace->id)
            ->where('id', $documentTypeId)
            ->firstOrFail();
    }

    /**
     * Filter the given tag ids down to those that actually belong to the workspace.
     *
     * @param  Workspace  $workspace  The workspace tags must belong to.
     * @param  array<int, string>  $tagIds  Candidate tag UUIDs, e.g. from client input.
     * @return array<int, string> The subset of $tagIds that exist and belong to $workspace.
     */
    private function scopedTagIds(Workspace $workspace, array $tagIds): array
    {
        return Tag::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', $tagIds)
            ->pluck('id')
            ->all();
    }
}
