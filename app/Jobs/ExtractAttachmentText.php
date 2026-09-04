<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Documents\FindDuplicateAttachment;
use App\Actions\Documents\SuggestDocumentMetadata;
use App\Enums\OcrStatus;
use App\Models\DocumentAttachment;
use App\Models\Task;
use App\Services\Ocr\AttachmentTextExtractor;
use App\Services\Ocr\TextFingerprint;
use App\Services\Ocr\UnreadableAttachment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use LogicException;
use Throwable;

/**
 * Extracts one attachment's text in the background and folds it into the
 * document's search index.
 *
 * The outcome is recorded twice, for two different readers. The attachment
 * carries the text and its status, which is what the document page shows next
 * to the file and what `Document::refreshOcrText()` reads. The `Task` row is
 * the workspace-wide view on the Tasks page, alongside exports and bulk moves,
 * and is what gives an admin somewhere to see and retry a failure without
 * opening documents one by one.
 *
 * Unlike those other task types this one takes no workspace lock: extraction is
 * scoped to a single file, so several may run at once (see `TaskType::lockKey`).
 */
class ExtractAttachmentText implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * OCR is slow by nature, so the usual short job timeout does not apply.
     *
     * Read from `archivum.ocr.job_timeout`, which derives it from the page cap
     * and the per-binary timeout, rather than being a number of its own. A
     * fixed 1800 used to sit below the 2400s worst case of twenty pages at
     * two minutes each, so raising `OCR_MAX_PAGES` silently started killing
     * extractions that were running perfectly well.
     */
    public int $timeout;

    /** @var int Retries, for a transient failure like the storage disk blipping. */
    public int $tries = 3;

    /** @var int Seconds between retries. */
    public int $backoff = 30;

    /**
     * @param DocumentAttachment $attachment The attachment whose text is extracted.
     * @param Task $task The task row tracking this extraction on the Tasks page.
     */
    public function __construct(
        public readonly DocumentAttachment $attachment,
        public readonly Task $task,
    ) {
        $this->timeout = (int) config('archivum.ocr.job_timeout');
    }

    /**
     * @param AttachmentTextExtractor $extractor Decides how to read the file and does it.
     * @param TextFingerprint $fingerprints Reduces the extracted text to a comparable fingerprint.
     * @param FindDuplicateAttachment $findDuplicate Looks for an earlier attachment with a matching fingerprint.
     * @param SuggestDocumentMetadata $suggest Reads values out of the text for the document's empty fields.
     *
     * @return void No return value; updates the attachment, its task, the document's mirrored text and the search index as a side effect.
     */
    public function handle(
        AttachmentTextExtractor $extractor,
        TextFingerprint $fingerprints,
        FindDuplicateAttachment $findDuplicate,
        SuggestDocumentMetadata $suggest,
    ): void {
        $this->attachment->markOcrProcessing();
        $this->task->markProcessing();

        try {
            $extracted = $extractor->handle($this->attachment);
        } catch (UnreadableAttachment $exception) {
            // The file itself is broken, so retrying would fail identically
            // three more times and then fill failed_jobs with something no
            // operator can act on. Record it and stop.
            //
            // Not rethrowing also keeps a corrupt upload from breaking the
            // upload request on installations running the `sync` queue driver,
            // where this job runs inline.
            $this->recordFailure($exception->getMessage());

            return;
        } catch (Throwable $exception) {
            $this->recordFailure($exception->getMessage());

            // Everything else — the disk, the engine — may well work on the
            // next attempt, so rethrow and let the queue retry. Not reported
            // here as well: that would log the same failure once per attempt.
            throw $exception;
        }

        // Every case listed rather than a `default`, so that adding a status to
        // the enum without deciding what it means here is a static analysis
        // failure at build time instead of a surprise in production. The three
        // in the last arm describe an attachment before or during extraction,
        // or a failure raised as an exception — the extractor cannot return them.
        match ($extracted->status) {
            OcrStatus::Completed => $this->attachment->markOcrCompleted($extracted->text),
            OcrStatus::Skipped => $this->attachment->markOcrSkipped(),
            OcrStatus::Unavailable => $this->attachment->markOcrUnavailable(),
            OcrStatus::Pending, OcrStatus::Processing, OcrStatus::Failed => throw new LogicException(
                "AttachmentTextExtractor returned {$extracted->status->value}, which is not an extraction outcome.",
            ),
        };

        if ($extracted->status === OcrStatus::Completed) {
            $this->fingerprint($extracted->text, $fingerprints, $findDuplicate);
        }

        $document = $this->attachment->document;

        $document?->refreshOcrText();

        // Read from the document's mirror rather than this attachment's text:
        // a document is often several pages, and the date is on the one that
        // happens to be extracted last as readily as the first.
        $document?->recordMetadataSuggestions($suggest->extract($document->ocr_text));

        $this->task->markCompleted([
            'filename' => $this->attachment->filename,
            'document_id' => $this->attachment->document_id,
            'outcome' => $extracted->status->value,
            'characters' => mb_strlen($extracted->text),
        ]);
    }

    /**
     * Fingerprint the extracted text and flag whatever it duplicates.
     *
     * Anything that goes wrong here is reported and swallowed. The text is what
     * this job exists to produce and it is already stored; failing the job over
     * a suggestion-grade extra would throw that away and retry the whole OCR
     * run to get it back.
     *
     * @param string $text The text just extracted.
     * @param TextFingerprint $fingerprints Reduces the text to a comparable fingerprint.
     * @param FindDuplicateAttachment $findDuplicate Looks for an earlier attachment with a matching fingerprint.
     *
     * @return void No return value; updates the attachment as a side effect.
     */
    private function fingerprint(
        string $text,
        TextFingerprint $fingerprints,
        FindDuplicateAttachment $findDuplicate,
    ): void {
        try {
            $simhash = $fingerprints->simhash($text);

            $this->attachment->recordTextFingerprint(
                $simhash,
                $simhash === null ? null : $findDuplicate->handle($this->attachment, $simhash),
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Record the failure once the retries are exhausted.
     *
     * `handle()` already marks it on each attempt, but a failure Laravel raises
     * around the job — a timeout, or the model going missing — never reaches
     * that catch, and would otherwise leave the attachment and its task stuck
     * on "processing" forever.
     *
     * @param Throwable|null $exception The failure, if there was one.
     *
     * @return void No return value; updates the attachment and its task as a side effect.
     */
    public function failed(?Throwable $exception): void
    {
        $this->recordFailure($exception?->getMessage() ?? 'Text extraction failed.');
    }

    /**
     * Mark both records as failed with the same message.
     *
     * @param string $message What went wrong.
     *
     * @return void No return value; saves the attachment and the task as a side effect.
     */
    private function recordFailure(string $message): void
    {
        $this->attachment->markOcrFailed($message);

        $this->task->markFailed($message);
    }
}
