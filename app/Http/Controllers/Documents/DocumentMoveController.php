<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use App\Actions\Documents\MoveDocument;
use App\Actions\Organization\FindAvailableLocation;
use App\Concerns\ResolvesWorkspaceRecords;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\StoreDocumentMoveRequest;
use App\Models\Document;
use App\Models\OrganizationNode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use LogicException;

class DocumentMoveController extends Controller
{
    use ResolvesWorkspaceRecords;

    /**
     * Move a document to an explicitly chosen node, or resolve a destination
     * automatically from a scheme's matching rules when no node is given.
     *
     * @param StoreDocumentMoveRequest $request The incoming request; carries either `node_id` or `scheme_id`/`criteria`.
     * @param Document $document The document being relocated.
     * @param MoveDocument $action Records the new location for $document.
     * @param FindAvailableLocation $findAvailableLocation Resolves an available node automatically when no explicit node is given.
     *
     * @return RedirectResponse Redirect back to the previous page.
     *
     * @throws AuthorizationException If the current user cannot update $document.
     * @throws ModelNotFoundException If the given node, or scheme, does not belong to the document's workspace.
     * @throws ValidationException If the resolved node belongs to a different workspace than $document.
     * @throws LogicException If automatic resolution is requested for a scheme that has no levels.
     */
    public function store(StoreDocumentMoveRequest $request, Document $document, MoveDocument $action, FindAvailableLocation $findAvailableLocation): RedirectResponse
    {
        $this->authorize('update', $document);

        $nodeId = $request->validated('node_id');

        $node = $nodeId !== null
            ? $this->scopedNode($document->workspace, $nodeId)
            : $this->resolveAutoNode($document, $findAvailableLocation, $request->validated('scheme_id'), $request->validated('criteria') ?? []);

        $action->handle($document, $node);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('document.moved')]);

        return back();
    }

    /**
     * Resolve a destination node automatically via the scheme's matching
     * rules, using the document's type together with the given criteria.
     *
     * @param Document $document The document being relocated; supplies the `document_type` criterion.
     * @param FindAvailableLocation $action Resolves the first available leaf node for the scheme and criteria.
     * @param string $schemeId The UUID of the organization scheme to resolve a destination within.
     * @param array<string, string> $criteria Additional matcher criteria (e.g. custom attributes) merged with the document type.
     *
     * @return OrganizationNode The resolved (possibly newly created) destination node.
     *
     * @throws ModelNotFoundException If no scheme with $schemeId exists within the document's workspace.
     * @throws LogicException If the resolved scheme has no levels.
     */
    private function resolveAutoNode(Document $document, FindAvailableLocation $action, string $schemeId, array $criteria): OrganizationNode
    {
        $scheme = $this->scopedScheme($document->workspace, $schemeId);

        return $action->handle($scheme, ['document_type' => $document->documentType->key, ...$criteria]);
    }
}
