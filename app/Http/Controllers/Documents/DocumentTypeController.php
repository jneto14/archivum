<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\StoreDocumentTypeRequest;
use App\Models\DocumentType;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;

class DocumentTypeController extends Controller
{
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

        return back();
    }
}
