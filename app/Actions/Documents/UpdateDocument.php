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
     * @param  array<string, mixed>|null  $metadata
     * @param  array<int, string>  $tagIds
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
