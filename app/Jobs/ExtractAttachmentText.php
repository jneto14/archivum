<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\OcrStatus;
use App\Models\DocumentAttachment;
use App\Services\Ocr\AttachmentTextExtractor;
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
 * Unlike exports and bulk moves, this does not create a `Task` row. Uploads
 * happen one file at a time and often in bursts, so a task per attachment
 * would bury the deliberate, user-triggered work on the Tasks page — and the
 * per-workspace lock those tasks use would serialise extraction that has no
 * reason to be serial. The state lives on the attachment instead, where the
 * document page can show it next to the file it belongs to.
 */
class ExtractAttachmentText implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * OCR is slow by nature, so the usual short job timeout does not apply. The
     * per-binary timeout in `config('archivum.ocr.timeout')` and the
     * `max_pages` cap are what actually bound the work; this is only a backstop.
     */
    public int $timeout = 1800;

    /** @var int Retries, for a transient failure like the storage disk blipping. */
    public int $tries = 3;

    /** @var int Seconds between retries. */
    public int $backoff = 30;

    /**
     * @param DocumentAttachment $attachment The attachment whose text is extracted.
     */
    public function __construct(public readonly DocumentAttachment $attachment) {}

    /**
     * @param AttachmentTextExtractor $extractor Decides how to read the file and does it.
     *
     * @return void No return value; updates the attachment, the document's mirrored text and the search index as a side effect.
     */
    public function handle(AttachmentTextExtractor $extractor): void
    {
        $this->attachment->markOcrProcessing();

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
            $this->attachment->markOcrFailed($exception->getMessage());

            return;
        } catch (Throwable $exception) {
            $this->attachment->markOcrFailed($exception->getMessage());

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

        $this->attachment->document?->refreshOcrText();
    }

    /**
     * Record the failure on the attachment once the retries are exhausted.
     *
     * `handle()` already marks it on each attempt, but a failure Laravel raises
     * around the job — a timeout, or the model going missing — never reaches
     * that catch, and would otherwise leave the attachment stuck on
     * "processing" forever.
     *
     * @param Throwable|null $exception The failure, if there was one.
     *
     * @return void No return value; updates the attachment as a side effect.
     */
    public function failed(?Throwable $exception): void
    {
        $this->attachment->markOcrFailed($exception?->getMessage() ?? 'Text extraction failed.');

        $this->attachment->document?->refreshOcrText();
    }
}
