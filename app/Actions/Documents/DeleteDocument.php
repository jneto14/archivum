<?php

namespace App\Actions\Documents;

use App\Models\Document;

class DeleteDocument
{
    /**
     * Delete a Document, cascading its tags and location history.
     *
     * @param  Document  $document  The document to delete.
     * @return void No return value; the document is deleted as a side effect.
     */
    public function handle(Document $document): void
    {
        $document->delete();
    }
}
