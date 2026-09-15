<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Documents\CreateDocument;
use App\Actions\Documents\SearchDocuments;
use App\Actions\Documents\TrashDocument;
use App\Actions\Documents\UpdateDocument;
use App\Concerns\ResolvesWorkspaceRecords;
use App\Enums\SearchMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\SearchDocumentsRequest;
use App\Http\Requests\Documents\StoreDocumentRequest;
use App\Http\Requests\Documents\UpdateDocumentRequest;
use App\Http\Resources\Api\V1\DocumentResource;
use App\Models\Document;
use App\Models\Workspace;
use App\Support\PageSize;
use App\Support\TableSort;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class DocumentController extends Controller
{
    use ResolvesWorkspaceRecords;

    /**
     * List a workspace's documents, filtered, sorted and paginated.
     *
     * This is also the search endpoint: `q` and `mode` are two of the filters
     * rather than a separate surface, which is what the interface's own
     * listing does. There is no second route to keep in step with this one.
     *
     * Runs through the same SearchDocuments action the pages use, where the
     * workspace scoping is hard-enforced and never client-controlled.
     *
     * @param SearchDocumentsRequest $request The incoming request with the validated query and filters.
     * @param Workspace $workspace The workspace whose documents are listed.
     * @param SearchDocuments $action Runs the filtered, paginated search.
     *
     * @return AnonymousResourceCollection A page of matching documents.
     *
     * @throws AuthorizationException If the token's user isn't a member of $workspace.
     */
    public function index(SearchDocumentsRequest $request, Workspace $workspace, SearchDocuments $action): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [Document::class, $workspace]);

        $results = $action->handle(
            $workspace,
            $request->validated('q'),
            [
                'document_type_id' => $request->validated('document_type_id'),
                'tag_ids' => $request->validated('tag_ids') ?? [],
                'from' => $request->validated('from'),
                'to' => $request->validated('to'),
                'node_id' => $request->validated('node_id'),
            ],
            SearchMode::fromRequestValue($request->validated('mode')),
            TableSort::fromRequest(
                $request,
                SearchDocuments::sortColumns(),
                SearchDocuments::DEFAULT_SORT,
                SearchDocuments::DEFAULT_DIRECTION,
            ),
            PageSize::fromRequest($request),
        );

        return DocumentResource::collection($results);
    }

    /**
     * Register a new document in the given workspace.
     *
     * @param StoreDocumentRequest $request The incoming request with the validated document attributes.
     * @param Workspace $workspace The workspace the document belongs to.
     * @param CreateDocument $action Creates the document and syncs its tags.
     *
     * @return JsonResponse The created document, with status 201.
     *
     * @throws AuthorizationException If the token's user cannot create documents in $workspace.
     * @throws ModelNotFoundException If the requested document type does not belong to $workspace.
     * @throws ValidationException If the workspace has reached its document limit.
     */
    public function store(StoreDocumentRequest $request, Workspace $workspace, CreateDocument $action): JsonResponse
    {
        $this->authorize('create', [Document::class, $workspace]);

        $document = $action->handle(
            $workspace,
            $request->user(),
            $this->scopedDocumentType($workspace, $request->validated('document_type_id')),
            $request->validated('title'),
            $request->validated('document_date'),
            $request->validated('metadata'),
            $this->scopedTagIds($workspace, $request->validated('tag_ids') ?? []),
        );

        return (new DocumentResource($this->loaded($document)))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Read a single document.
     *
     * @param Document $document The document being read.
     *
     * @return DocumentResource The document with its type, tags, creator and current location.
     *
     * @throws AuthorizationException If the token's user cannot view $document.
     */
    public function show(Document $document): DocumentResource
    {
        $this->authorize('view', $document);

        return new DocumentResource($this->loaded($document));
    }

    /**
     * Update a document's attributes and tags.
     *
     * @param UpdateDocumentRequest $request The incoming request with the validated document attributes.
     * @param Document $document The document being updated.
     * @param UpdateDocument $action Applies the update and resyncs tags.
     *
     * @return DocumentResource The updated document.
     *
     * @throws AuthorizationException If the token's user cannot update $document.
     * @throws ModelNotFoundException If the requested document type does not belong to the document's workspace.
     */
    public function update(UpdateDocumentRequest $request, Document $document, UpdateDocument $action): DocumentResource
    {
        $this->authorize('update', $document);

        $workspace = $document->workspace;

        $action->handle(
            $document,
            $this->scopedDocumentType($workspace, $request->validated('document_type_id')),
            $request->validated('title'),
            $request->validated('document_date'),
            $request->validated('metadata'),
            $this->scopedTagIds($workspace, $request->validated('tag_ids') ?? []),
        );

        return new DocumentResource($this->loaded($document->refresh()));
    }

    /**
     * Move a document to the trash.
     *
     * Reversible, and deliberately not a purge: the trash endpoints are where
     * something is destroyed for good, and they answer to a narrower policy.
     *
     * @param Document $document The document being trashed.
     * @param TrashDocument $action Soft-deletes the document and its attachments.
     *
     * @return JsonResponse An empty 204.
     *
     * @throws AuthorizationException If the token's user cannot delete $document.
     */
    public function destroy(Document $document, TrashDocument $action): JsonResponse
    {
        $this->authorize('delete', $document);

        $action->handle($document);

        return new JsonResponse(status: 204);
    }

    /**
     * Load the relations every single-document response carries.
     *
     * @param Document $document The document to load relations onto.
     *
     * @return Document The same document, with its type, tags, creator and current location loaded.
     */
    private function loaded(Document $document): Document
    {
        return $document->load(['documentType', 'tags', 'creator', 'currentLocation.node.level']);
    }
}
