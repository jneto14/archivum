<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\StoreDocumentTypeRequest;
use App\Http\Requests\Documents\UpdateDocumentTypeRequest;
use App\Http\Resources\Api\V1\DocumentTypeResource;
use App\Models\DocumentType;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class DocumentTypeController extends Controller
{
    /**
     * List the document types configured in the given workspace.
     *
     * Unpaginated, deliberately: a workspace has a handful of these, a client
     * filing documents needs the whole set to map its own vocabulary onto
     * theirs, and paging a list of six would only make that harder.
     *
     * @param Workspace $workspace The workspace whose document types are listed.
     *
     * @return AnonymousResourceCollection The workspace's document types, ordered by name.
     *
     * @throws AuthorizationException If the token's user isn't a member of $workspace.
     */
    public function index(Workspace $workspace): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [DocumentType::class, $workspace]);

        return DocumentTypeResource::collection(
            DocumentType::query()
                ->where('workspace_id', $workspace->id)
                ->withCount('documents')
                ->orderBy('name')
                ->get(),
        );
    }

    /**
     * Create a new document type within the given workspace.
     *
     * @param StoreDocumentTypeRequest $request The incoming request with the validated name and key.
     * @param Workspace $workspace The workspace the document type is created in.
     *
     * @return JsonResponse The created type, with status 201.
     *
     * @throws AuthorizationException If the token's user cannot create document types in $workspace.
     */
    public function store(StoreDocumentTypeRequest $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('create', [DocumentType::class, $workspace]);

        $type = DocumentType::query()->create([
            'workspace_id' => $workspace->id,
            'name' => $request->validated('name'),
            'key' => $request->validated('key'),
        ]);

        return (new DocumentTypeResource($type))->response()->setStatusCode(201);
    }

    /**
     * Update a document type's name and key.
     *
     * @param UpdateDocumentTypeRequest $request The incoming request with the validated name and key.
     * @param DocumentType $documentType The document type being updated.
     *
     * @return DocumentTypeResource The updated type.
     *
     * @throws AuthorizationException If the token's user cannot update $documentType.
     */
    public function update(UpdateDocumentTypeRequest $request, DocumentType $documentType): DocumentTypeResource
    {
        $this->authorize('update', $documentType);

        $documentType->update([
            'name' => $request->validated('name'),
            'key' => $request->validated('key'),
        ]);

        return new DocumentTypeResource($documentType);
    }

    /**
     * Delete a document type, as long as no documents are still filed under it.
     *
     * @param DocumentType $documentType The document type to delete.
     *
     * @return JsonResponse An empty 204.
     *
     * @throws AuthorizationException If the token's user cannot delete $documentType.
     * @throws ValidationException If $documentType still has documents assigned to it.
     */
    public function destroy(DocumentType $documentType): JsonResponse
    {
        $this->authorize('delete', $documentType);

        if ($documentType->documents()->exists()) {
            throw ValidationException::withMessages([
                'document_type' => __('document.type_in_use'),
            ]);
        }

        $documentType->delete();

        return new JsonResponse(status: 204);
    }
}
