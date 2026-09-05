<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\StoreDocumentTypeRequest;
use App\Http\Requests\Documents\UpdateDocumentTypeRequest;
use App\Models\DocumentType;
use App\Models\Workspace;
use App\Support\TableSort;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class DocumentTypeController extends Controller
{
    /**
     * List the document types configured in the given workspace.
     *
     * @param Request $request The incoming request, used to resolve the acting user and the chosen order.
     * @param Workspace $workspace The workspace whose document types are listed.
     *
     * @return Response The rendered document types page.
     *
     * @throws AuthorizationException If the current user isn't a member of $workspace.
     */
    public function index(Request $request, Workspace $workspace): Response
    {
        $this->authorize('viewAny', [DocumentType::class, $workspace]);

        $sort = TableSort::fromRequest($request, [
            'name' => 'document_types.name',
            'key' => 'document_types.key',
            'documents_count' => 'documents_count',
        ], 'name');

        $types = DocumentType::query()
            ->where('workspace_id', $workspace->id)
            ->withCount('documents')
            ->tap(fn (Builder $query) => $sort->apply($query, 'document_types.id'))
            ->get();

        return Inertia::render('document-types/index', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'sort' => $sort->toArray(),
            'documentTypes' => $types->map(fn (DocumentType $type) => [
                'id' => $type->id,
                'name' => $type->name,
                'key' => $type->key,
                'documents_count' => $type->documents_count,
            ])->values()->all(),
            'canManage' => $workspace->isManageableBy($request->user()),
        ]);
    }

    /**
     * Create a new document type within the given workspace.
     *
     * @param StoreDocumentTypeRequest $request The incoming request with the validated name and key.
     * @param Workspace $workspace The workspace the document type is created in.
     *
     * @return RedirectResponse Redirect back to the previous page.
     *
     * @throws AuthorizationException If the current user cannot create document types in $workspace.
     */
    public function store(StoreDocumentTypeRequest $request, Workspace $workspace): RedirectResponse
    {
        $this->authorize('create', [DocumentType::class, $workspace]);

        DocumentType::query()->create([
            'workspace_id' => $workspace->id,
            'name' => $request->validated('name'),
            'key' => $request->validated('key'),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('document.type_created')]);

        return back();
    }

    /**
     * Update a document type's attributes.
     *
     * @param UpdateDocumentTypeRequest $request The incoming request with the validated name and key.
     * @param DocumentType $documentType The document type being updated.
     *
     * @return RedirectResponse Redirect back to the previous page.
     *
     * @throws AuthorizationException If the current user cannot update $documentType.
     */
    public function update(UpdateDocumentTypeRequest $request, DocumentType $documentType): RedirectResponse
    {
        $this->authorize('update', $documentType);

        $documentType->update([
            'name' => $request->validated('name'),
            'key' => $request->validated('key'),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('document.type_updated')]);

        return back();
    }

    /**
     * Delete a document type, as long as no documents are still assigned to it.
     *
     * @param DocumentType $documentType The document type to delete.
     *
     * @return RedirectResponse Redirect back to the previous page.
     *
     * @throws AuthorizationException If the current user cannot delete $documentType.
     * @throws ValidationException If $documentType still has documents assigned to it.
     */
    public function destroy(DocumentType $documentType): RedirectResponse
    {
        $this->authorize('delete', $documentType);

        if ($documentType->documents()->exists()) {
            throw ValidationException::withMessages([
                'document_type' => __('document.type_in_use'),
            ]);
        }

        $documentType->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('document.type_deleted')]);

        return back();
    }
}
