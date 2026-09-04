<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Actions\Organization\CountFiledDocuments;
use App\Models\Document;
use App\Models\DocumentLocation;
use App\Models\OrganizationNode;
use Illuminate\Validation\ValidationException;

class MoveDocument
{
    public function __construct(private readonly CountFiledDocuments $countFiledDocuments) {}

    /**
     * Record a new physical location assignment for a Document.
     *
     * @param Document $document The document being relocated.
     * @param OrganizationNode $node The organization node to place the document under.
     *
     * @return DocumentLocation The newly created location record for this move.
     *
     * @throws ValidationException If $node does not belong to the same workspace as $document, or is already holding as many documents as its level allows.
     */
    public function handle(Document $document, OrganizationNode $node): DocumentLocation
    {
        $this->assertNodeBelongsToWorkspace($document, $node);
        $this->assertNodeHasRoom($document, $node);

        return DocumentLocation::query()->create([
            'document_id' => $document->id,
            'organization_node_id' => $node->id,
        ]);
    }

    /**
     * @param Document $document The document being relocated.
     * @param OrganizationNode $node The candidate destination node.
     *
     * @return void No return value when valid.
     *
     * @throws ValidationException If $node's scheme workspace differs from $document's workspace.
     */
    private function assertNodeBelongsToWorkspace(Document $document, OrganizationNode $node): void
    {
        if ($node->level->scheme->workspace_id !== $document->workspace_id) {
            throw ValidationException::withMessages([
                'node_id' => __('document.location_workspace_mismatch'),
            ]);
        }
    }

    /**
     * Refuse to file a document into a leaf node already holding as many documents as
     * its level allows. A shelf with room for six holds six, however the destination was
     * chosen — the suggestions leave full nodes out, but a node picked by id has to be
     * checked here, which is the only place every caller passes through.
     *
     * A document already filed at $node does not consume a second place there, so it is
     * left out of the count.
     *
     * @param Document $document The document being relocated.
     * @param OrganizationNode $node The candidate destination node.
     *
     * @return void No return value when there is room.
     *
     * @throws ValidationException If $node is a leaf at its level's capacity.
     */
    private function assertNodeHasRoom(Document $document, OrganizationNode $node): void
    {
        $level = $node->level;

        if ($level->capacity === null || !$level->isLeaf()) {
            return;
        }

        $filed = $this->countFiledDocuments->at($node);

        if ($document->currentLocation?->organization_node_id === $node->id) {
            $filed--;
        }

        if ($filed >= $level->capacity) {
            throw ValidationException::withMessages([
                'node_id' => __('document.location_full'),
            ]);
        }
    }
}
