<?php

namespace App\Http\Controllers\Documents;

use App\Actions\Documents\SearchDocuments;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\SearchDocumentsRequest;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Models\Workspace;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DocumentSearchController extends Controller
{
    /**
     * Search documents within a workspace, filtered by query, document type,
     * tags, and date range.
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
            ],
        );

        return DocumentResource::collection($results);
    }
}
