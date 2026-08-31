<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\BulkMoveDocuments;
use App\Jobs\ExportWorkspaceDocuments;
use App\Jobs\ExtractAttachmentText;
use App\Models\DocumentAttachment;
use App\Models\OrganizationNode;
use App\Models\Task;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use LogicException;

class RetryTask
{
    /**
     * Re-run a failed task from scratch, guarded by the same concurrency lock
     * a fresh dispatch of its type would use — where its type has one.
     *
     * @param Task $task The failed task to retry.
     *
     * @return void No return value; the task and its underlying job are re-dispatched as a side effect.
     *
     * @throws ValidationException If $task isn't in the Failed state, if another task of the same type is
     *                             already running for the workspace, or if the attachment a text extraction
     *                             task refers to has since been deleted.
     */
    public function handle(Task $task): void
    {
        if ($task->status !== TaskStatus::Failed) {
            throw ValidationException::withMessages([
                'task' => __('workspace.only_failed_tasks_can_retry'),
            ]);
        }

        // Null for attachment text extraction, which is scoped to one file and
        // has no workspace-wide exclusivity to re-establish.
        $lockKey = $task->type->lockKey($task->workspace_id);
        $lock = $lockKey === null ? null : Cache::lock($lockKey, 600);

        if ($lock !== null && !$lock->get()) {
            throw ValidationException::withMessages([
                'task' => __($task->type === TaskType::DocumentExport
                    ? 'workspace.export_already_running'
                    : 'workspace.bulk_move_already_running'),
            ]);
        }

        // Resolved before the task is reset below, so that retrying a task
        // whose attachment has since been deleted fails without leaving the row
        // stuck on "queued" with nothing to run it.
        $attachment = $task->type === TaskType::AttachmentTextExtraction
            ? $this->attachmentFor($task)
            : null;

        $lockOwner = $lock?->owner();

        $task->update([
            'status' => TaskStatus::Queued,
            'result' => null,
            'started_at' => null,
            'finished_at' => null,
        ]);

        // The `?? throw` arms restate what the branches above already
        // guarantee — a locked type holds a lock, an extraction has its
        // attachment — in a form the type checker can see.
        match ($task->type) {
            TaskType::DocumentExport => ExportWorkspaceDocuments::dispatch(
                $task,
                $lockOwner ?? throw new LogicException('A document export must hold a workspace lock.'),
            ),
            TaskType::BulkDocumentMove => BulkMoveDocuments::dispatch(
                $task,
                OrganizationNode::query()->where('id', $task->payload['source_node_id'])->firstOrFail(),
                OrganizationNode::query()->where('id', $task->payload['target_node_id'])->firstOrFail(),
                $lockOwner ?? throw new LogicException('A bulk document move must hold a workspace lock.'),
            ),
            TaskType::AttachmentTextExtraction => ExtractAttachmentText::dispatch(
                $attachment ?? throw new LogicException('A text extraction retry must have resolved its attachment.'),
                $task,
            ),
        };
    }

    /**
     * Resolve the attachment a text extraction task was created for.
     *
     * The attachment may have been deleted since the task failed, in which case
     * there is nothing to retry — and the task row stays as history, since its
     * payload still records which file it was.
     *
     * @param Task $task The text extraction task being retried.
     *
     * @return DocumentAttachment The attachment to re-extract.
     *
     * @throws ValidationException If the attachment no longer exists.
     */
    private function attachmentFor(Task $task): DocumentAttachment
    {
        $attachment = DocumentAttachment::query()
            ->where('id', $task->payload['attachment_id'] ?? null)
            ->first();

        if ($attachment === null) {
            throw ValidationException::withMessages([
                'task' => __('workspace.attachment_no_longer_exists'),
            ]);
        }

        return $attachment;
    }
}
