<?php

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\StoreDocumentTypeRequest;
use App\Models\DocumentType;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;

class DocumentTypeController extends Controller
{
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
