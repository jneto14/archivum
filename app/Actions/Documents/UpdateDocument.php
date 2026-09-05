<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Jobs\LearnDocumentIntakeLabels;
use App\Models\Document;
use App\Models\DocumentType;
use Illuminate\Support\Facades\DB;

class UpdateDocument
{
    public function __construct(private readonly SuggestDocumentMetadata $suggest) {}

    /**
     * Update a Document's attributes and resync its tags.
     *
     * Also takes the document off the intake review queue once the edit has
     * left nothing to suggest — whether the values were accepted from the
     * suggestions or typed by hand, they are filled in either way, and a queue
     * that lists documents with nothing waiting on them is one nobody trusts.
     *
     * And it is where the archive learns to read. A user filling in a field the
     * reader missed is the entire signal this feature runs on, and the moment
     * they save is the moment it exists — see LearnDocumentIntakeLabels.
     *
     * @param Document $document The document to update.
     * @param DocumentType $type The document type to assign.
     * @param string $title The document's new title.
     * @param string|null $documentDate The date the document was issued/dated, if known.
     * @param array<string, mixed>|null $metadata Arbitrary type-specific metadata to store alongside the document.
     * @param array<int, string> $tagIds IDs of tags the document should have after this update (replaces existing tags).
     *
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

            if (filled($document->metadata_suggestions) && $this->suggest->handle($document) === []) {
                $document->recordMetadataSuggestions([]);
            }

            // Only when the fields themselves moved: a retitling teaches
            // nothing, and mining reads a page of text.
            if ($document->wasChanged('metadata')) {
                LearnDocumentIntakeLabels::dispatch($document)->afterCommit();
            }

            return $document;
        });
    }
}
