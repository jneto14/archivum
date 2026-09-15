<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Documents\MoveDocument;
use App\Actions\Organization\FindAvailableLocation;
use App\Concerns\ResolvesWorkspaceRecords;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\StoreDocumentMoveRequest;
use App\Http\Resources\Api\V1\DocumentResource;
use App\Models\Document;
use App\Models\OrganizationNode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use LogicException;

class DocumentMoveController extends Controller
{
    use ResolvesWorkspaceRecords;

    /**
     * Move a document to a node, named explicitly or resolved from a scheme's
     * matching rules.
     *
     * The second form is what makes this worth having over a plain update: a
     * client filing a batch does not know the archive's shelves, and asking
     * the scheme where this document belongs is the whole point of the rules.
     *
     * @param StoreDocumentMoveRequest $request The incoming request; carries either `node_id` or `scheme_id`/`criteria`.
     * @param Document $document The document being relocated.
     * @param MoveDocument $action Records the new location for $document.
     * @param FindAvailableLocation $findAvailableLocation Resolves an available node when no explicit node is given.
     *
     * @return DocumentResource The document, with its new current location.
     *
     * @throws AuthorizationException If the token's user cannot update $document.
     * @throws ModelNotFoundException If the given node, or scheme, does not belong to the document's workspace.
     * @throws ValidationException If the resolved node belongs to a different workspace than $document.
     * @throws LogicException If automatic resolution is requested for a scheme that has no levels.
     */
    public function store(
        StoreDocumentMoveRequest $request,
        Document $document,
        MoveDocument $action,
        FindAvailableLocation $findAvailableLocation,
    ): DocumentResource {
        $this->authorize('update', $document);

        $nodeId = $request->validated('node_id');

        $node = $nodeId !== null
            ? $this->scopedNode($document->workspace, $nodeId)
            : $this->resolveAutoNode($document, $findAvailableLocation, $request->validated('scheme_id'), $request->validated('criteria') ?? []);

        $action->handle($document, $node);

        return new DocumentResource(
            $document->refresh()->load(['documentType', 'tags', 'creator', 'currentLocation.node.level']),
        );
    }

    /**
     * Resolve a destination node from the scheme's matching rules, using the
     * document's type together with the given criteria.
     *
     * @param Document $document The document being relocated; supplies the `document_type` criterion.
     * @param FindAvailableLocation $action Resolves the first available leaf node for the scheme and criteria.
     * @param string $schemeId The UUID of the organization scheme to resolve within.
     * @param array<string, string> $criteria Additional matcher criteria, merged with the document type.
     *
     * @return OrganizationNode The resolved, possibly newly created, destination node.
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
