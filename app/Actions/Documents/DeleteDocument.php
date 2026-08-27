<?php

namespace App\Actions\Documents;

use App\Models\Document;

class DeleteDocument
{
    /**
     * Delete a Document, cascading its tags and location history.
     *
     * @param  Document  $document  The document to delete.
     */
    public function handle(Document $document): void
    {
        $document->delete();
    }
}
