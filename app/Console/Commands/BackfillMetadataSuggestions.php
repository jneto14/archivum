<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Documents\SuggestDocumentMetadata;
use App\Models\Document;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

#[Signature('archivum:backfill-suggestions {--all : Re-read documents that already have findings stored, for when the heuristics have improved}')]
#[Description('Read stored attachment text for metadata suggestions, so documents extracted before this feature reach the review queue')]
class BackfillMetadataSuggestions extends Command
{
    /**
     * Read every document's existing text and record what it contains.
     *
     * Extraction records this as it goes, so this exists for the documents that
     * were extracted before it did — on an installation upgrading into this
     * feature, that is the entire archive, and without this they would never
     * appear on the review queue however long anybody waited.
     *
     * `--all` is for the other case: the heuristics improving, and everything
     * already scanned deserving a second reading.
     *
     * @param SuggestDocumentMetadata $suggest Reads the values out of the text.
     *
     * @return int The command's exit code.
     */
    public function handle(SuggestDocumentMetadata $suggest): int
    {
        $found = 0;
        $read = 0;

        Document::query()
            ->whereNotNull('ocr_text')
            ->when(!$this->option('all'), fn ($query) => $query->whereNull('metadata_suggestions'))
            ->chunkById(100, function (Collection $documents) use ($suggest, &$found, &$read): void {
                foreach ($documents as $document) {
                    $findings = $suggest->extract($document->ocr_text);

                    $document->recordMetadataSuggestions($findings);

                    $read++;
                    $found += count($findings);
                }
            });

        $this->info("Read {$read} document(s); found {$found} value(s) to suggest.");

        return self::SUCCESS;
    }
}
