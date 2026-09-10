<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Documents\FindDuplicateAttachment;
use App\Actions\Documents\SuggestDocumentMetadata;
use App\Models\DocumentAttachment;
use App\Models\Task;
use App\Services\Ocr\AttachmentTextExtractor;
use App\Services\Ocr\TextFingerprint;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Reads a run of attachments in one go, for a bulk re-extraction.
 *
 * A job per attachment costs a queue round trip each — a reserve, a delete and
 * a batch bookkeeping write — which was a quarter of the time on an archive of
 * text-layer PDFs, where reading the file is tens of milliseconds. A chunk pays
 * that once for twenty-five files.
 *
 * The work itself is `ExtractAttachmentText`, called directly rather than
 * reimplemented: one file's extraction is the same thing whoever asked for it,
 * and an upload still queues that job on its own with its own task row.
 *
 * Two things a chunk has to answer for that a single job did not:
 *
 * **A failure must not take its neighbours with it.** `ExtractAttachmentText`
 * rethrows anything that is not a broken file, so the queue can retry it.
 * Retrying a chunk would re-read the twenty-four that already succeeded, so
 * they are caught here instead and the chunk carries on. Nothing is lost: the
 * attachment already carries its own failed status and error, and
 * `ocr:reextract --status=failed` is how they are picked up again.
 *
 * **A chunk must still fit the timeout.** That is sized for one attachment's
 * worst case — twenty pages at two minutes each — so twenty-five of those
 * would not fit. Rather than raise it, the chunk watches the clock and hands
 * whatever it has not reached back to the batch as a fresh chunk.
 */
class ExtractAttachmentTexts implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The share of the timeout a chunk will start a new attachment within.
     *
     * The margin is what the attachment it starts at the last moment gets to
     * finish in. Deliberately generous: being killed loses the progress count
     * for the whole chunk, while stopping early costs one extra queue round
     * trip.
     */
    private const BUDGET = 0.6;

    /** @var int Seconds this chunk may run, the same ceiling one extraction has. */
    public int $timeout;

    /** @var int One attempt. A failure is recorded on the attachment it belongs to, and re-running the chunk would re-read everything in it that worked. */
    public int $tries = 1;

    /**
     * @param list<string> $attachmentIds The attachments to read, in order.
     * @param Task|null $task The sweep's task row, for the progress it reports.
     */
    public function __construct(
        public readonly array $attachmentIds,
        public readonly ?Task $task = null,
    ) {
        $this->timeout = (int) config('archivum.ocr.job_timeout');
    }

    /**
     * @param AttachmentTextExtractor $extractor Decides how to read each file and does it.
     * @param TextFingerprint $fingerprints Reduces the extracted text to a comparable fingerprint.
     * @param FindDuplicateAttachment $findDuplicate Looks for an earlier attachment with a matching fingerprint.
     * @param SuggestDocumentMetadata $suggest Reads values out of the text for each document's empty fields.
     *
     * @return void No return value; updates the attachments, their documents and the sweep's progress as a side effect.
     */
    public function handle(
        AttachmentTextExtractor $extractor,
        TextFingerprint $fingerprints,
        FindDuplicateAttachment $findDuplicate,
        SuggestDocumentMetadata $suggest,
    ): void {
        $deadline = microtime(true) + ($this->timeout * self::BUDGET);
        $read = 0;

        foreach ($this->attachmentIds as $index => $attachmentId) {
            if ($this->batch()?->cancelled()) {
                break;
            }

            if (microtime(true) >= $deadline && $this->handBack(array_slice($this->attachmentIds, $index))) {
                break;
            }

            // Resolved one at a time rather than as a collection up front: on a
            // chunk of scans this job runs for minutes, and an attachment
            // trashed while it does is one that should no longer be read.
            $attachment = DocumentAttachment::query()->find($attachmentId);

            if ($attachment === null) {
                continue;
            }

            try {
                (new ExtractAttachmentText($attachment))->handle($extractor, $fingerprints, $findDuplicate, $suggest);
            } catch (Throwable $exception) {
                // Already recorded on the attachment by the job itself; reported
                // so it reaches the logs, and then stepped over.
                report($exception);
            }

            $read++;
        }

        $this->task?->advanceProgress($read);
    }

    /**
     * Hand the attachments this chunk did not reach back to the batch.
     *
     * @param list<string> $remaining The attachments still to read.
     *
     * @return bool True if they were handed back, false if there was no batch to hand them to — in which case the chunk keeps going, since dropping them silently would be worse than overrunning.
     */
    private function handBack(array $remaining): bool
    {
        $batch = $this->batch();

        if ($batch === null || $remaining === []) {
            return false;
        }

        $batch->add([new self($remaining, $this->task)]);

        return true;
    }
}
