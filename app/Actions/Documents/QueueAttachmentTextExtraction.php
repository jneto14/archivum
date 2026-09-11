<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\ExtractAttachmentText;
use App\Models\DocumentAttachment;
use App\Models\Task;
use App\Models\User;

class QueueAttachmentTextExtraction
{
    /**
     * Queue a reading of whatever file an attachment is holding, with a Tasks
     * page row to follow it by.
     *
     * Every way a file arrives ends here: an upload, a replacement, and an
     * earlier version restored. They are the same work to everything
     * downstream, and the branch that matters — an installation with
     * extraction switched off, where the attachment has to be settled straight
     * away so the document page says so instead of showing a "pending" that
     * will never resolve — is the one worth having in a single place.
     *
     * Unlike `ReextractAttachmentText`, nothing here throws. That one is a
     * request somebody made on its own and can be refused; this runs on the
     * back of storing a file, which has already happened and must not be
     * undone because the archive cannot read it.
     *
     * @param DocumentAttachment $attachment The attachment whose file should be read.
     * @param User $user The user whose action brought the file in.
     *
     * @return Task|null The queued task, or null where extraction is switched off.
     */
    public function handle(DocumentAttachment $attachment, User $user): ?Task
    {
        if (!config('archivum.ocr.enabled')) {
            $attachment->markOcrUnavailable();

            return null;
        }

        // No lock, unlike exports and bulk moves: extraction is scoped to one
        // file and several may run at once. See `TaskType::lockKey()`.
        //
        // The filename lives in the payload rather than only in the result,
        // because `Task::markFailed()` replaces the result wholesale — and a
        // failed row that cannot say which file it was is not worth showing.
        $task = Task::query()->create([
            'workspace_id' => $attachment->document->workspace_id,
            'user_id' => $user->id,
            'type' => TaskType::AttachmentTextExtraction,
            'status' => TaskStatus::Queued,
            'payload' => [
                'attachment_id' => $attachment->id,
                'document_id' => $attachment->document_id,
                'filename' => $attachment->filename,
            ],
        ]);

        // Queued rather than inline: OCR on a multi-page scan takes seconds per
        // page, and the request that brought the file in must not wait for it.
        ExtractAttachmentText::dispatch($attachment, $task);

        return $task;
    }
}
