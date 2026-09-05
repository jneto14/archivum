<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Documents\SuggestDocumentMetadata;
use App\Models\Document;
use App\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Reads a workspace's already-extracted documents again, with vocabulary that
 * has changed since they were last read.
 *
 * Without this, accepting a label was a promise the application did not keep.
 * Every document already in the archive stored what the text was found to
 * contain at the time it was extracted — back when the accepted word meant
 * nothing — and nothing ever looked at them again. The new label would only
 * ever apply to documents filed from that moment on, which is the opposite of
 * why anybody accepts one: the whole point is the archive that is already
 * there. The only fix was `archivum:backfill-suggestions --all`, run by hand,
 * by somebody who knew it existed.
 *
 * Retiring a label is the same job for the same reason, in reverse: readings
 * made with a word that turned out to be wrong should stop being offered.
 */
class RereadWorkspaceSuggestions implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Documents read per query, so a large archive is re-read in bounded memory. */
    private const int DOCUMENT_CHUNK = 200;

    /** @var int Seconds. Long, because this is the whole archive rather than one page. */
    public int $timeout = 900;

    /** @var int Retries, for a transient failure like the database blipping mid-chunk. */
    public int $tries = 3;

    /** @var int Seconds between retries. */
    public int $backoff = 60;

    /**
     * @param Workspace $workspace The workspace whose documents are re-read.
     */
    public function __construct(public readonly Workspace $workspace) {}

    /**
     * One pass per workspace at a time.
     *
     * An admin answering six candidates in a row would otherwise start six
     * passes over the same archive. Unique until processing rather than until
     * finished, so a decision made while a pass is running is not swallowed by
     * it — that pass may already be past the documents the new answer affects.
     *
     * @return string The workspace being re-read.
     */
    public function uniqueId(): string
    {
        return $this->workspace->id;
    }

    /**
     * @param SuggestDocumentMetadata $suggest Reads each document's stored text and records what is worth suggesting.
     *
     * @return void No return value; rewrites each document's stored findings as a side effect.
     */
    public function handle(SuggestDocumentMetadata $suggest): void
    {
        Document::query()
            ->where('workspace_id', $this->workspace->id)
            ->whereNotNull('ocr_text')
            ->chunkById(self::DOCUMENT_CHUNK, function (Collection $documents) use ($suggest): void {
                foreach ($documents as $document) {
                    $suggest->record($document);
                }
            });
    }
}
