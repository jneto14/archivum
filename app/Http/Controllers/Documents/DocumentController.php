<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use App\Actions\Documents\CreateDocument;
use App\Actions\Documents\DeleteDocument;
use App\Actions\Documents\SearchDocuments;
use App\Actions\Documents\UpdateDocument;
use App\Actions\Organization\SuggestDocumentLocations;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\SearchDocumentsRequest;
use App\Http\Requests\Documents\StoreDocumentRequest;
use App\Http\Requests\Documents\UpdateDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\OrganizationScheme;
use App\Models\Tag;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class DocumentController extends Controller
{
    /**
     * List documents in the given workspace, filtered and paginated.
     *
     * @param SearchDocumentsRequest $request The incoming request with the validated search/filter query.
     * @param Workspace $workspace The workspace whose documents are listed.
     * @param SearchDocuments $action Runs the filtered, paginated Scout search.
     *
     * @return Response The rendered documents index page.
     *
     * @throws AuthorizationException If the current user isn't a member of $workspace.
     */
    public function index(SearchDocumentsRequest $request, Workspace $workspace, SearchDocuments $action): Response
    {
        $this->authorize('viewAny', [Document::class, $workspace]);

        $filters = [
            'document_type_id' => $request->validated('document_type_id'),
            'tag_ids' => $request->validated('tag_ids') ?? [],
            'from' => $request->validated('from'),
            'to' => $request->validated('to'),
        ];

        $results = $action->handle($workspace, $request->validated('q'), $filters);

        return Inertia::render('documents/index', [
            'documents' => DocumentResource::collection($results),
            'filters' => [...$filters, 'q' => $request->validated('q')],
            'documentTypes' => $this->workspaceDocumentTypes($workspace),
            'tags' => $this->workspaceTags($workspace),
        ]);
    }

    /**
     * Show the form for registering a new document in the given workspace.
     *
     * @param Workspace $workspace The workspace the new document will belong to.
     *
     * @return Response The rendered document form page.
     *
     * @throws AuthorizationException If the current user cannot create documents in $workspace.
     */
    public function create(Workspace $workspace): Response
    {
        $this->authorize('create', [Document::class, $workspace]);

        return Inertia::render('documents/form', [
            'workspaceId' => $workspace->id,
            'document' => null,
            'documentTypes' => $this->workspaceDocumentTypes($workspace),
            'tags' => $this->workspaceTags($workspace),
        ]);
    }

    /**
     * Show a single document's details, attachments and location history.
     *
     * @param Request $request The incoming request, used to resolve the acting user.
     * @param Document $document The document being viewed.
     *
     * @return Response The rendered document show page.
     *
     * @throws AuthorizationException If the current user cannot view $document.
     */
    public function show(Request $request, Document $document): Response
    {
        $this->authorize('view', $document);

        $document->load([
            'documentType',
            'tags',
            'currentLocation.node.level.scheme',
            'attachments.uploader',
            'locations.node',
            'creator',
        ]);

        $canFile = $document->workspace->isAdmin($request->user());
        $scheme = $document->currentLocation?->node?->level->scheme
            ?? OrganizationScheme::query()->where('workspace_id', $document->workspace_id)->oldest()->first();

        return Inertia::render('documents/show', [
            'document' => new DocumentResource($document),
            'canFile' => $canFile,
            'locationSuggestions' => ($canFile && $scheme !== null)
                ? app(SuggestDocumentLocations::class)->handle($document, $scheme)
                : [],
        ]);
    }

    /**
     * Show the form for editing an existing document.
     *
     * @param Document $document The document being edited.
     *
     * @return Response The rendered document form page.
     *
     * @throws AuthorizationException If the current user cannot update $document.
     */
    public function edit(Document $document): Response
    {
        $this->authorize('update', $document);

        $document->load(['documentType', 'tags']);

        return Inertia::render('documents/form', [
            'workspaceId' => $document->workspace_id,
            'document' => new DocumentResource($document),
            'documentTypes' => $this->workspaceDocumentTypes($document->workspace),
            'tags' => $this->workspaceTags($document->workspace),
        ]);
    }

    /**
     * Create a new document within the given workspace.
     *
     * @param StoreDocumentRequest $request The incoming request with the validated document attributes.
     * @param Workspace $workspace The workspace the document is created in.
     * @param CreateDocument $action Creates the document and syncs its tags.
     *
     * @return RedirectResponse Redirect to the newly created document's show page.
     *
     * @throws AuthorizationException If the current user cannot create documents in $workspace.
     * @throws ModelNotFoundException If the requested document type does not belong to $workspace.
     * @throws ValidationException If the workspace's document limit would be exceeded.
     */
    public function store(StoreDocumentRequest $request, Workspace $workspace, CreateDocument $action): RedirectResponse
    {
        $this->authorize('create', [Document::class, $workspace]);

        $type = $this->scopedDocumentType($workspace, $request->validated('document_type_id'));

        $document = $action->handle(
            $workspace,
            $request->user(),
            $type,
            $request->validated('title'),
            $request->validated('document_date'),
            $request->validated('metadata'),
            $this->scopedTagIds($workspace, $request->validated('tag_ids') ?? []),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('document.created')]);

        return redirect()->route('documents.show', $document);
    }

    /**
     * Update a document's attributes and tags.
     *
     * @param UpdateDocumentRequest $request The incoming request with the validated document attributes.
     * @param Document $document The document being updated.
     * @param UpdateDocument $action Applies the update and resyncs tags.
     *
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

        Inertia::flash('toast', ['type' => 'success', 'message' => __('document.updated')]);

        return back();
    }

    /**
     * Delete a document.
     *
     * @param Document $document The document to delete.
     * @param DeleteDocument $action Deletes the document and its cascading records.
     *
     * @return RedirectResponse Redirect to the deleted document's (now former) workspace's documents index — its own show/edit page no longer exists to go "back" to.
     *
     * @throws AuthorizationException If the current user cannot delete $document.
     */
    public function destroy(Document $document, DeleteDocument $action): RedirectResponse
    {
        $this->authorize('delete', $document);

        $workspace = $document->workspace;

        $action->handle($document);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('document.deleted')]);

        return redirect()->route('documents.index', $workspace);
    }

    /**
     * Resolve a document type by id, scoped to the given workspace.
     *
     * @param Workspace $workspace The workspace the document type must belong to.
     * @param string $documentTypeId The UUID of the document type to resolve.
     *
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
     * @param Workspace $workspace The workspace tags must belong to.
     * @param array<int, string> $tagIds Candidate tag UUIDs, e.g. from client input.
     *
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

    /**
     * List a workspace's document types for populating a type picker.
     *
     * @param Workspace $workspace The workspace whose document types are listed.
     *
     * @return EloquentCollection<int, DocumentType> The workspace's document types, ordered by name.
     */
    private function workspaceDocumentTypes(Workspace $workspace): EloquentCollection
    {
        return DocumentType::query()->where('workspace_id', $workspace->id)->orderBy('name')->get(['id', 'name']);
    }

    /**
     * List a workspace's tags for populating a tag picker.
     *
     * @param Workspace $workspace The workspace whose tags are listed.
     *
     * @return EloquentCollection<int, Tag> The workspace's tags, ordered by name.
     */
    private function workspaceTags(Workspace $workspace): EloquentCollection
    {
        return Tag::query()->where('workspace_id', $workspace->id)->orderBy('name')->get(['id', 'name']);
    }
}
