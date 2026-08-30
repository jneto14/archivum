<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspaces;

use App\Actions\Workspace\RetryTask;
use App\Actions\Workspace\StartDocumentExport;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TaskController extends Controller
{
    /**
     * List the workspace's background tasks, most recent first.
     *
     * @param Workspace $workspace The workspace whose tasks are listed.
     *
     * @return Response The rendered "Tarefas" Inertia page.
     *
     * @throws AuthorizationException If the current user cannot view $workspace's tasks.
     */
    public function index(Workspace $workspace): Response
    {
        $this->authorize('viewAny', [Task::class, $workspace]);

        $tasks = Task::query()
            ->where('workspace_id', $workspace->id)
            ->with('user')
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('workspace/tasks', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'tasks' => $tasks->map(fn (Task $task) => [
                'id' => $task->id,
                'type' => $task->type->value,
                'status' => $task->status->value,
                'triggered_by' => $task->user->name,
                'result' => $task->result,
                'started_at' => $task->started_at?->toIso8601String(),
                'finished_at' => $task->finished_at?->toIso8601String(),
                'created_at' => $task->created_at?->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    /**
     * Trigger a document export for the workspace.
     *
     * @param Workspace $workspace The workspace whose documents are exported.
     * @param Request $request The incoming request; used to resolve the current user.
     * @param StartDocumentExport $action Acquires the export lock, creates the task, and dispatches the job.
     *
     * @return RedirectResponse Redirect back to the previous page.
     *
     * @throws AuthorizationException If the current user cannot trigger a task for $workspace.
     * @throws ValidationException If a document export is already running for $workspace.
     */
    public function store(Workspace $workspace, Request $request, StartDocumentExport $action): RedirectResponse
    {
        $this->authorize('create', [Task::class, $workspace]);

        $action->handle($workspace, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('workspace.export_started')]);

        return back();
    }

    /**
     * Retry a failed task belonging to the workspace.
     *
     * @param Workspace $workspace The workspace the task must belong to.
     * @param Task $task The failed task to retry.
     * @param RetryTask $action Resets the task and re-dispatches its underlying job.
     *
     * @return RedirectResponse Redirect back to the previous page.
     *
     * @throws AuthorizationException If the current user cannot retry $task.
     * @throws ValidationException If $task isn't Failed, or another task of the same type is already running.
     */
    public function retry(Workspace $workspace, Task $task, RetryTask $action): RedirectResponse
    {
        abort_if($task->workspace_id !== $workspace->id, 404);

        $this->authorize('retry', $task);

        $action->handle($task);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('workspace.export_retried')]);

        return back();
    }

    /**
     * Download a completed task's result file.
     *
     * @param Workspace $workspace The workspace the task must belong to.
     * @param Task $task The completed task whose result is downloaded.
     *
     * @return StreamedResponse A streamed download of the task's result file.
     *
     * @throws AuthorizationException If the current user cannot view $task.
     */
    public function download(Workspace $workspace, Task $task): StreamedResponse
    {
        return $this->downloadResult($workspace, $task);
    }

    /**
     * Download a completed task's result file via a signed, expiring link,
     * as emailed to the user once their export finishes. The `signed`
     * route middleware only proves the URL hasn't been tampered with or
     * expired — the user must still be authenticated and currently a
     * workspace admin at click-time, same as the in-app download.
     *
     * @param Workspace $workspace The workspace the task must belong to.
     * @param Task $task The completed task whose result is downloaded.
     *
     * @return StreamedResponse A streamed download of the task's result file.
     *
     * @throws AuthorizationException If the current user cannot view $task.
     */
    public function downloadSigned(Workspace $workspace, Task $task): StreamedResponse
    {
        return $this->downloadResult($workspace, $task);
    }

    /**
     * @param Workspace $workspace The workspace the task must belong to.
     * @param Task $task The completed task whose result is downloaded.
     *
     * @return StreamedResponse A streamed download of the task's result file.
     *
     * @throws AuthorizationException If the current user cannot view $task.
     */
    private function downloadResult(Workspace $workspace, Task $task): StreamedResponse
    {
        abort_if($task->workspace_id !== $workspace->id, 404);

        $this->authorize('view', $task);

        abort_unless($task->type === TaskType::DocumentExport, 404);
        abort_unless($task->status === TaskStatus::Completed && $task->result !== null, 404);
        abort_unless(Storage::disk($task->result['disk'])->exists($task->result['path']), 404);

        return Storage::disk($task->result['disk'])->download($task->result['path']);
    }
}
