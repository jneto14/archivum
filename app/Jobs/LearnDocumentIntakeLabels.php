<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Documents\LearnIntakeLabels;
use App\Models\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Reads one document for words the archive could be recognising values by.
 *
 * Dispatched at the two moments the signal actually exists: somebody saves
 * metadata on a document, and a document's text finishes extracting. Those are
 * the only times what the document can teach changes, and between them they
 * replace the weekly sweep of every document in every workspace that this used
 * to be — the same ten thousand documents re-read to find out what the fifty
 * edited ones said.
 *
 * On the queue rather than in the request because it is a regex over a page of
 * text, and saving a document should not wait for it. Nothing it writes is read
 * by anything until a workspace admin accepts it, so nothing downstream is
 * waiting either.
 */
class LearnDocumentIntakeLabels implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int Retries. What it reads is stored, so a second attempt sees exactly what the first did. */
    public int $tries = 3;

    /** @var int Seconds between retries. */
    public int $backoff = 30;

    /**
     * @param Document $document The document to mine.
     */
    public function __construct(public readonly Document $document) {}

    /**
     * One queued reading per document at a time.
     *
     * Somebody correcting three fields in a row queues three of these, and the
     * first would read what the third is about to write. Unique until
     * processing rather than until finished, so an edit made *during* a reading
     * still queues one of its own instead of being swallowed.
     *
     * @return string The document being read.
     */
    public function uniqueId(): string
    {
        return $this->document->id;
    }

    /**
     * @param LearnIntakeLabels $learn Reads the document and records what it suggests calling things.
     *
     * @return void No return value; writes candidate labels and their evidence as a side effect.
     */
    public function handle(LearnIntakeLabels $learn): void
    {
        $learn->learn($this->document);
    }
}
