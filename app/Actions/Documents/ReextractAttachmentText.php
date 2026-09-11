<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Enums\OcrStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\ExtractAttachmentText;
use App\Models\DocumentAttachment;
use App\Models\Task;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ReextractAttachmentText
{
    /**
     * Read one stored attachment's text again.
     *
     * The same job, task type and Tasks-page row an upload produces, because
     * to everyone downstream this is the same work — the only difference is
     * that the file was already here. What the previous reading left behind is
     * cleared by `markOcrProcessing()` when the job starts, so the second
     * reading replaces the first rather than being merged into it (ARC-122).
     *
     * @param DocumentAttachment $attachment The attachment to read again.
     * @param User $user The user who asked for it.
     *
     * @return Task The queued task tracking the extraction.
     *
     * @throws ValidationException If extraction is switched off on this installation, or the attachment is already being read.
     */
    public function handle(DocumentAttachment $attachment, User $user): Task
    {
        if (!config('archivum.ocr.enabled')) {
            throw ValidationException::withMessages([
                'attachment' => __('document.ocr_disabled'),
            ]);
        }

        // Queuing a second reading of a file already being read would have the
        // two racing to write the same columns, and the loser's text is the
        // one that sticks.
        if (in_array($attachment->ocr_status, [OcrStatus::Pending, OcrStatus::Processing], true)) {
            throw ValidationException::withMessages([
                'attachment' => __('document.ocr_already_running'),
            ]);
        }

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

        ExtractAttachmentText::dispatch($attachment, $task);

        return $task;
    }
}
