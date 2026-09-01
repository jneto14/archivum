<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Actions\Workspace\CalculateWorkspaceUsage;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\ExtractAttachmentText;
use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class UploadAttachment
{
    public function __construct(private readonly CalculateWorkspaceUsage $calculateUsage) {}

    /**
     * Store an uploaded file and attach it to a Document.
     *
     * @param Document $document The document the file is attached to; determines the owning workspace and storage path.
     * @param UploadedFile $file The uploaded file to store.
     * @param User $uploader The user recorded as having uploaded the file.
     *
     * @return DocumentAttachment The newly created attachment record.
     *
     * @throws ValidationException If the workspace has reached its attachment count limit, or storing $file would exceed its storage limit.
     */
    public function handle(Document $document, UploadedFile $file, User $uploader): DocumentAttachment
    {
        $this->guard($document->workspace, 1, (int) $file->getSize());

        return $this->store($document, $file, $uploader);
    }

    /**
     * Store a batch of uploaded files against a Document, all or nothing.
     *
     * The limits are checked once for the whole batch, before anything is
     * written. A batch that does not fit is rejected outright rather than
     * stored up to the point where it stops fitting: a half-applied upload
     * leaves the user to work out which files made it, and leaves behind
     * extraction tasks for a batch they will probably retry in full.
     *
     * @param Document $document The document the files are attached to.
     * @param list<UploadedFile> $files The uploaded files to store, in the order they were queued.
     * @param User $uploader The user recorded as having uploaded the files.
     *
     * @return list<DocumentAttachment> The newly created attachment records, in the same order.
     *
     * @throws ValidationException If the batch would take the workspace past its attachment count or storage limit.
     */
    public function handleMany(Document $document, array $files, User $uploader): array
    {
        $sizes = array_map(static fn (UploadedFile $file): int => (int) $file->getSize(), $files);

        $this->guard($document->workspace, count($files), array_sum($sizes));

        return array_map(
            fn (UploadedFile $file): DocumentAttachment => $this->store($document, $file, $uploader),
            $files,
        );
    }

    /**
     * Reject the upload unless the workspace has room for all of it.
     *
     * @param Workspace $workspace The workspace whose limits apply.
     * @param int $count How many attachments are about to be created.
     * @param int $bytes Their combined size in bytes.
     *
     * @throws ValidationException If either the attachment count or the storage limit would be exceeded.
     */
    private function guard(Workspace $workspace, int $count, int $bytes): void
    {
        $limits = $workspace->limits;

        if ($limits === null) {
            return;
        }

        $currentCount = $this->calculateUsage->attachments($workspace);

        if ($limits->exceedsAttachments($currentCount, $count)) {
            $remaining = $limits->remainingAttachments($currentCount) ?? 0;

            // Naming the number of free slots is what keeps an all-or-nothing
            // rejection from being a dead end: without it, someone with two
            // slots left and ten files picked has no way to know what to retry.
            throw ValidationException::withMessages([
                'files' => match (true) {
                    $remaining === 0 => __('document.attachment_limit_reached'),
                    $remaining === 1 => __('document.attachment_limit_remaining_one'),
                    default => __('document.attachment_limit_remaining_other', ['count' => $remaining]),
                },
            ]);
        }

        if ($limits->exceedsStorage($this->calculateUsage->storageBytes($workspace), $bytes)) {
            throw ValidationException::withMessages([
                'files' => __('document.storage_limit_exceeded'),
            ]);
        }
    }

    /**
     * Write one file to storage, record it, and queue its text extraction.
     *
     * Assumes the workspace limits have already been checked by `guard()`.
     *
     * @param Document $document The document the file is attached to.
     * @param UploadedFile $file The uploaded file to store.
     * @param User $uploader The user recorded as having uploaded the file.
     *
     * @return DocumentAttachment The newly created attachment record.
     */
    private function store(Document $document, UploadedFile $file, User $uploader): DocumentAttachment
    {
        $workspace = $document->workspace;
        $disk = config('archivum.attachments.disk');
        $path = $file->store("documents/{$document->id}", $disk);

        $attachment = DocumentAttachment::query()->create([
            'document_id' => $document->id,
            'uploaded_by' => $uploader->id,
            'disk' => $disk,
            'path' => $path,
            'filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'checksum' => hash_file('sha256', $file->getRealPath()),
        ]);

        $this->calculateUsage->forget($workspace);

        // Queued rather than inline: OCR on a multi-page scan takes seconds per
        // page, and the upload response must not wait for it. With extraction
        // switched off the attachment is settled straight away as unavailable,
        // so the document page says so instead of showing a "pending" that will
        // never resolve — and no task is created for work that will not happen.
        if (!config('archivum.ocr.enabled')) {
            $attachment->markOcrUnavailable();

            return $attachment;
        }

        // No lock, unlike exports and bulk moves: extraction is scoped to one
        // file and several may run at once. See `TaskType::lockKey()`.
        //
        // The filename lives in the payload rather than only in the result,
        // because `Task::markFailed()` replaces the result wholesale — and a
        // failed row that cannot say which file it was is not worth showing.
        $task = Task::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $uploader->id,
            'type' => TaskType::AttachmentTextExtraction,
            'status' => TaskStatus::Queued,
            'payload' => [
                'attachment_id' => $attachment->id,
                'document_id' => $document->id,
                'filename' => $attachment->filename,
            ],
        ]);

        ExtractAttachmentText::dispatch($attachment, $task);

        return $attachment;
    }
}
