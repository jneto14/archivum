<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\BulkMoveDocuments;
use App\Jobs\ExportWorkspaceDocuments;
use App\Models\OrganizationNode;
use App\Models\Task;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class RetryTask
{
    /**
     * Re-run a failed task from scratch, guarded by the same concurrency lock
     * a fresh dispatch of its type would use.
     *
     * @param Task $task The failed task to retry.
     *
     * @return void No return value; the task and its underlying job are re-dispatched as a side effect.
     *
     * @throws ValidationException If $task isn't in the Failed state, or another task of the same type is
     *                             already running for the workspace.
     */
    public function handle(Task $task): void
    {
        if ($task->status !== TaskStatus::Failed) {
            throw ValidationException::withMessages([
                'task' => __('workspace.only_failed_tasks_can_retry'),
            ]);
        }

        $lock = Cache::lock($task->type->lockKey($task->workspace_id), 600);

        if (!$lock->get()) {
            throw ValidationException::withMessages([
                'task' => __($task->type === TaskType::DocumentExport
                    ? 'workspace.export_already_running'
                    : 'workspace.bulk_move_already_running'),
            ]);
        }

        $task->update([
            'status' => TaskStatus::Queued,
            'result' => null,
            'started_at' => null,
            'finished_at' => null,
        ]);

        match ($task->type) {
            TaskType::DocumentExport => ExportWorkspaceDocuments::dispatch($task, $lock->owner()),
            TaskType::BulkDocumentMove => BulkMoveDocuments::dispatch(
                $task,
                OrganizationNode::query()->where('id', $task->payload['source_node_id'])->firstOrFail(),
                OrganizationNode::query()->where('id', $task->payload['target_node_id'])->firstOrFail(),
                $lock->owner(),
            ),
        };
    }
}
