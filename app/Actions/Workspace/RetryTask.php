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
     * Re-run a failed task from scratch.
     *
     * @param Task $task The failed task to retry.
     *
     * @return void No return value; the task and its underlying job are re-dispatched as a side effect.
     *
     * @throws ValidationException If $task isn't in the Failed state, or (for lock-guarded types) another task of
     *                             the same type is already running for the workspace.
     */
    public function handle(Task $task): void
    {
        if ($task->status !== TaskStatus::Failed) {
            throw ValidationException::withMessages([
                'task' => __('workspace.only_failed_tasks_can_retry'),
            ]);
        }

        match ($task->type) {
            TaskType::DocumentExport => $this->retryDocumentExport($task),
            TaskType::BulkDocumentMove => $this->retryBulkDocumentMove($task),
        };
    }

    /**
     * @param Task $task The failed document export to retry.
     *
     * @return void No return value; the task is reset and re-dispatched as a side effect.
     *
     * @throws ValidationException If a document export is already running for the task's workspace.
     */
    private function retryDocumentExport(Task $task): void
    {
        $lock = Cache::lock($task->type->lockKey($task->workspace_id), 600);

        if (!$lock->get()) {
            throw ValidationException::withMessages([
                'task' => __('workspace.export_already_running'),
            ]);
        }

        $this->resetTask($task);

        ExportWorkspaceDocuments::dispatch($task, $lock->owner());
    }

    /**
     * @param Task $task The failed bulk move to retry; its payload carries the source/target node ids.
     *
     * @return void No return value; the task is reset and re-dispatched as a side effect.
     */
    private function retryBulkDocumentMove(Task $task): void
    {
        $this->resetTask($task);

        BulkMoveDocuments::dispatch(
            $task,
            OrganizationNode::query()->where('id', $task->payload['source_node_id'])->firstOrFail(),
            OrganizationNode::query()->where('id', $task->payload['target_node_id'])->firstOrFail(),
        );
    }

    /**
     * @param Task $task The task to reset back to its initial, queued state.
     *
     * @return void No return value; persists the reset as a side effect.
     */
    private function resetTask(Task $task): void
    {
        $task->update([
            'status' => TaskStatus::Queued,
            'result' => null,
            'started_at' => null,
            'finished_at' => null,
        ]);
    }
}
