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
use Illuminate\Http\RedirectResponse;

class DocumentController extends Controller
{
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

    public function destroy(Document $document, DeleteDocument $action): RedirectResponse
    {
        $this->authorize('delete', $document);

        $action->handle($document);

        return back();
    }

    private function scopedDocumentType(Workspace $workspace, string $documentTypeId): DocumentType
    {
        return DocumentType::query()
            ->where('workspace_id', $workspace->id)
            ->where('id', $documentTypeId)
            ->firstOrFail();
    }

    /**
     * @param  array<int, string>  $tagIds
     * @return array<int, string>
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
