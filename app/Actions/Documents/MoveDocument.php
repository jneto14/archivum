<?php

namespace App\Actions\Documents;

use App\Models\Document;
use App\Models\DocumentLocation;
use App\Models\OrganizationNode;
use Illuminate\Validation\ValidationException;

class MoveDocument
{
    /**
     * Record a new physical location assignment for a Document.
     */
    public function handle(Document $document, OrganizationNode $node): DocumentLocation
    {
        $this->assertNodeBelongsToWorkspace($document, $node);

        return DocumentLocation::query()->create([
            'document_id' => $document->id,
            'organization_node_id' => $node->id,
        ]);
    }

    private function assertNodeBelongsToWorkspace(Document $document, OrganizationNode $node): void
    {
        if ($node->level->scheme->workspace_id !== $document->workspace_id) {
            throw ValidationException::withMessages([
                'node_id' => __('document.location_workspace_mismatch'),
            ]);
        }
    }
}
