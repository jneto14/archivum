<?php

namespace App\Http\Controllers\Documents;

use App\Actions\Documents\MoveDocument;
use App\Actions\Organization\FindAvailableLocation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\StoreDocumentMoveRequest;
use App\Models\Document;
use App\Models\OrganizationNode;
use App\Models\OrganizationScheme;
use Illuminate\Http\RedirectResponse;

class DocumentMoveController extends Controller
{
    public function store(StoreDocumentMoveRequest $request, Document $document, MoveDocument $action, FindAvailableLocation $findAvailableLocation): RedirectResponse
    {
        $this->authorize('update', $document);

        $nodeId = $request->validated('node_id');

        $node = $nodeId !== null
            ? $this->resolveExplicitNode($document, $nodeId)
            : $this->resolveAutoNode($document, $findAvailableLocation, $request->validated('scheme_id'), $request->validated('criteria') ?? []);

        $action->handle($document, $node);

        return back();
    }

    private function resolveExplicitNode(Document $document, string $nodeId): OrganizationNode
    {
        return OrganizationNode::query()
            ->whereHas('level.scheme', fn ($query) => $query->where('workspace_id', $document->workspace_id))
            ->where('id', $nodeId)
            ->firstOrFail();
    }

    /**
     * @param  array<string, string>  $criteria
     */
    private function resolveAutoNode(Document $document, FindAvailableLocation $action, string $schemeId, array $criteria): OrganizationNode
    {
        $scheme = OrganizationScheme::query()
            ->where('workspace_id', $document->workspace_id)
            ->where('id', $schemeId)
            ->firstOrFail();

        return $action->handle($scheme, ['document_type' => $document->documentType->key, ...$criteria]);
    }
}
