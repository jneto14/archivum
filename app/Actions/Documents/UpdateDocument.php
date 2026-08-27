<?php

namespace App\Actions\Documents;

use App\Models\Document;
use App\Models\DocumentType;
use Illuminate\Support\Facades\DB;

class UpdateDocument
{
    /**
     * Update a Document's attributes and resync its tags.
     *
     * @param  Document  $document  The document to update.
     * @param  DocumentType  $type  The document type to assign.
     * @param  string  $title  The document's new title.
     * @param  string|null  $documentDate  The date the document was issued/dated, if known.
     * @param  array<string, mixed>|null  $metadata  Arbitrary type-specific metadata to store alongside the document.
     * @param  array<int, string>  $tagIds  IDs of tags the document should have after this update (replaces existing tags).
     * @return Document The updated document.
     */
    public function handle(Document $document, DocumentType $type, string $title, ?string $documentDate, ?array $metadata, array $tagIds): Document
    {
        return DB::transaction(function () use ($document, $type, $title, $documentDate, $metadata, $tagIds): Document {
            $document->update([
                'document_type_id' => $type->id,
                'title' => $title,
                'document_date' => $documentDate,
                'metadata' => $metadata,
            ]);

            $document->tags()->sync($tagIds);

            return $document;
        });
    }
}
