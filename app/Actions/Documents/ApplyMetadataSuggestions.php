<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Jobs\LearnDocumentIntakeLabels;
use App\Models\Document;
use Illuminate\Support\Facades\DB;

/**
 * Writes the suggestions somebody accepted onto the document, and takes it off
 * the review queue either way.
 *
 * The caller names the kinds to accept, never the values. Those are looked up
 * again here from the document's own findings, so nothing this route writes can
 * be anything other than what the application read off the page itself.
 */
class ApplyMetadataSuggestions
{
    public function __construct(private readonly SuggestDocumentMetadata $suggest) {}

    /**
     * Accept some of $document's outstanding suggestions.
     *
     * The document leaves the queue whichever kinds were named, including none
     * at all: it has been looked at, which is what the queue is asking for.
     * Anything not accepted is simply not written.
     *
     * @param Document $document The document being reviewed.
     * @param array<int, string> $kinds The kinds of suggestion to accept, e.g. ['document_date', 'amount'].
     *
     * @return Document The updated document.
     */
    public function handle(Document $document, array $kinds): Document
    {
        return DB::transaction(function () use ($document, $kinds): Document {
            $metadata = $document->metadata ?? [];
            $documentDate = $document->document_date;

            foreach ($this->suggest->handle($document) as $suggestion) {
                if (!in_array($suggestion['kind'], $kinds, true)) {
                    continue;
                }

                if ($suggestion['kind'] === 'document_date') {
                    $documentDate = $suggestion['value'];

                    continue;
                }

                $metadata[$suggestion['key']] = $suggestion['value'];
            }

            $document->update([
                'document_date' => $documentDate,
                'metadata' => $metadata === [] ? null : $metadata,
            ]);

            $document->recordMetadataSuggestions([]);

            // Mostly a confirmation of what the reader already knew — these
            // values were found by labels it has. It teaches where the document
            // also carries fields somebody filled in by hand, which this is
            // often the last step of.
            if ($document->wasChanged('metadata')) {
                LearnDocumentIntakeLabels::dispatch($document)->afterCommit();
            }

            return $document;
        });
    }
}
