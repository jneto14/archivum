<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Workspace\RetryTask;
use App\Actions\Workspace\StartBulkTextExtraction;
use App\Actions\Workspace\StartDocumentExport;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TaskResource;
use App\Models\Task;
use App\Models\Workspace;
use App\Support\PageSize;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The background work a workspace has going, and the two things that start it.
 */
class TaskController extends Controller
{
    /**
     * List a workspace's tasks, most recent first.
     *
     * Paginated, and filterable by `status` and `type`, because reading an
     * attachment creates a task per uploaded file: a client waiting on an
     * export would otherwise have to page past however many readings have run
     * since it asked.
     *
     * @param Request $request The incoming request, read for filters and the page size.
     * @param Workspace $workspace The workspace whose tasks are listed.
     *
     * @return AnonymousResourceCollection A page of tasks.
     *
     * @throws AuthorizationException If the token's user cannot see $workspace's tasks.
     */
    public function index(Request $request, Workspace $workspace): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [Task::class, $workspace]);

        $status = TaskStatus::tryFrom((string) $request->query('status'));
        $type = TaskType::tryFrom((string) $request->query('type'));

        $tasks = Task::query()
            ->where('workspace_id', $workspace->id)
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
            ->when($type !== null, fn (Builder $query) => $query->where('type', $type))
            ->with('user')
            ->latest('created_at')
            ->paginate(PageSize::fromRequest($request))
            ->withQueryString();

        return TaskResource::collection($tasks);
    }

    /**
     * Read one task, which is how a client waits for work it started.
     *
     * @param Workspace $workspace The workspace the task must belong to.
     * @param Task $task The task being read.
     *
     * @return TaskResource The task, with its status and progress.
     *
     * @throws AuthorizationException If the token's user cannot view $task.
     * @throws NotFoundHttpException If $task does not belong to $workspace.
     */
    public function show(Workspace $workspace, Task $task): TaskResource
    {
        abort_if($task->workspace_id !== $workspace->id, 404);

        $this->authorize('view', $task);

        return new TaskResource($task->load('user'));
    }

    /**
     * Start a document export for the workspace.
     *
     * @param Request $request The incoming request, used to resolve the acting user.
     * @param Workspace $workspace The workspace whose documents are exported.
     * @param StartDocumentExport $action Takes the export lock, creates the task and dispatches the job.
     *
     * @return JsonResponse The queued task, with status 202.
     *
     * @throws AuthorizationException If the token's user cannot start tasks for $workspace.
     * @throws ValidationException If an export is already running for $workspace.
     */
    public function store(Request $request, Workspace $workspace, StartDocumentExport $action): JsonResponse
    {
        $this->authorize('create', [Task::class, $workspace]);

        $task = $action->handle($workspace, $request->user());

        return (new TaskResource($task->load('user')))->response()->setStatusCode(202);
    }

    /**
     * Read every attachment in the workspace again.
     *
     * One task stands for the whole sweep, and its progress is counted in
     * attachments rather than in queued jobs.
     *
     * @param Request $request The incoming request, used to resolve the acting user.
     * @param Workspace $workspace The workspace whose attachments are re-read.
     * @param StartBulkTextExtraction $action Creates the task and dispatches the first chunk.
     *
     * @return JsonResponse The queued task, with status 202.
     *
     * @throws AuthorizationException If the token's user cannot start tasks for $workspace.
     * @throws ValidationException If extraction is switched off, or a sweep is already running.
     */
    public function reextract(Request $request, Workspace $workspace, StartBulkTextExtraction $action): JsonResponse
    {
        $this->authorize('create', [Task::class, $workspace]);

        $task = $action->handle($workspace, $request->user());

        return (new TaskResource($task->load('user')))->response()->setStatusCode(202);
    }

    /**
     * Run a failed task again.
     *
     * @param Workspace $workspace The workspace the task must belong to.
     * @param Task $task The failed task to retry.
     * @param RetryTask $action Resets the task and dispatches it again.
     *
     * @return TaskResource The task, queued afresh.
     *
     * @throws AuthorizationException If the token's user cannot retry $task.
     * @throws NotFoundHttpException If $task does not belong to $workspace.
     * @throws ValidationException If the task is not in a state that can be retried.
     */
    public function retry(Workspace $workspace, Task $task, RetryTask $action): TaskResource
    {
        abort_if($task->workspace_id !== $workspace->id, 404);

        $this->authorize('retry', $task);

        $action->handle($task);

        return new TaskResource($task->refresh()->load('user'));
    }

    /**
     * Download a finished export.
     *
     * Every one of these checks is a 404 rather than a 403 or a 422: from a
     * client's side there is either a file here or there is not, and the
     * reasons there might not be — wrong type of task, still running, failed,
     * pruned off the disk — are not a distinction worth leaking.
     *
     * @param Workspace $workspace The workspace the task must belong to.
     * @param Task $task The completed export whose file is downloaded.
     *
     * @return StreamedResponse A streamed download of the export.
     *
     * @throws AuthorizationException If the token's user cannot view $task.
     * @throws NotFoundHttpException If there is no downloadable result.
     */
    public function download(Workspace $workspace, Task $task): StreamedResponse
    {
        abort_if($task->workspace_id !== $workspace->id, 404);

        $this->authorize('view', $task);

        abort_unless($task->type === TaskType::DocumentExport, 404);
        abort_unless($task->status === TaskStatus::Completed && $task->result !== null, 404);
        abort_unless(Storage::disk($task->result['disk'])->exists($task->result['path']), 404);

        return Storage::disk($task->result['disk'])->download($task->result['path']);
    }
}
