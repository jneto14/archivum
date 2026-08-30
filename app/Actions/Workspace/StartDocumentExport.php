<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\ExportWorkspaceDocuments;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class StartDocumentExport
{
    /**
     * Queue a CSV export of every document in the workspace, guarded by an
     * atomic lock so two exports can't run concurrently for the same workspace.
     *
     * @param Workspace $workspace The workspace whose documents are exported.
     * @param User $user The user who triggered the export.
     *
     * @return Task The newly created, queued task.
     *
     * @throws ValidationException If a document export is already running for $workspace.
     */
    public function handle(Workspace $workspace, User $user): Task
    {
        $lock = Cache::lock(TaskType::DocumentExport->lockKey($workspace->id), 600);

        if (!$lock->get()) {
            throw ValidationException::withMessages([
                'task' => __('workspace.export_already_running'),
            ]);
        }

        $task = Task::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'type' => TaskType::DocumentExport,
            'status' => TaskStatus::Queued,
        ]);

        ExportWorkspaceDocuments::dispatch($task, $lock->owner());

        return $task;
    }
}
