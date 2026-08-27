<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Models\Document;
use App\Models\DocumentLocation;
use App\Models\OrganizationNode;
use Illuminate\Validation\ValidationException;

class MoveDocument
{
    /**
     * Record a new physical location assignment for a Document.
     *
     * @param Document $document The document being relocated.
     * @param OrganizationNode $node The organization node to place the document under.
     *
     * @return DocumentLocation The newly created location record for this move.
     *
     * @throws ValidationException If $node does not belong to the same workspace as $document.
     */
    public function handle(Document $document, OrganizationNode $node): DocumentLocation
    {
        $this->assertNodeBelongsToWorkspace($document, $node);

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
}
