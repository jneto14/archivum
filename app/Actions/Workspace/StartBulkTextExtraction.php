<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\QueueWorkspaceReextractions;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Support\ReextractionFilter;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use LogicException;

class StartBulkTextExtraction
{
    /**
     * How long the sweep's lock is held for.
     *
     * Far longer than the export's ten minutes, because a sweep is a batch of
     * thousands rather than one job: a lock that expires under a running sweep
     * lets a second one start and reset the attachments the first is part-way
     * through. The batch's callback releases it, so this ceiling only applies
     * when a worker dies mid-sweep.
     */
    private const LOCK_SECONDS = 86400;

    /**
     * Queue a re-reading of the workspace's stored attachments.
     *
     * One task row stands for the whole sweep. The extractions themselves are
     * batched without task rows of their own: a row per attachment would bury
     * the Tasks page under however many files the archive holds, and none of
     * them would say anything the sweep's own row does not (ARC-122).
     *
     * @param Workspace $workspace The workspace whose attachments are re-read.
     * @param User $user The user who triggered the sweep.
     * @param ReextractionFilter $filter Which of the workspace's attachments to cover.
     * @param int|null $limit Stop after this many attachments, or null for all of them.
     *
     * @return Task The newly created, queued task.
     *
     * @throws ValidationException If a bulk re-extraction is already running for $workspace, or if nothing matches the filter.
     */
    public function handle(
        Workspace $workspace,
        User $user,
        ReextractionFilter $filter = new ReextractionFilter(),
        ?int $limit = null,
    ): Task {
        $total = $this->count($workspace, $filter, $limit);

        if ($total === 0) {
            throw ValidationException::withMessages([
                'task' => __('workspace.nothing_to_reextract'),
            ]);
        }

        $lockKey = TaskType::BulkAttachmentTextExtraction->lockKey($workspace->id)
            ?? throw new LogicException('A bulk text extraction must have a workspace lock.');

        $lock = Cache::lock($lockKey, self::LOCK_SECONDS);

        if (!$lock->get()) {
            throw ValidationException::withMessages([
                'task' => __('workspace.reextraction_already_running'),
            ]);
        }

        $task = Task::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'type' => TaskType::BulkAttachmentTextExtraction,
            'status' => TaskStatus::Queued,
            'payload' => ['total' => $total] + $filter->toPayload(),
        ]);

        $taskId = $task->id;
        $lockOwner = $lock->owner();

        $batch = Bus::batch([
            new QueueWorkspaceReextractions($task, $filter, $limit),
        ])
            // One unreadable file among ten thousand must not stop the other
            // 9,999 — and every extraction records its own failure on the
            // attachment either way, which is where somebody would look.
            ->allowFailures()
            ->name("Re-extract text: {$workspace->name}")
            // On the batch, not on each job: `Batch::add()` pushes with the
            // batch's queue and never reads a job's own `queue` property.
            ->onQueue((string) config('archivum.ocr.bulk_queue'))
            ->finally(function (Batch $batch) use ($taskId, $lockKey, $lockOwner): void {
                Cache::restoreLock($lockKey, $lockOwner)->release();

                $task = Task::query()->find($taskId);

                if ($task === null) {
                    return;
                }

                // The loader records how many it queued once it has finished
                // queueing, so the absence of that number means it never got
                // there — the sweep fell over before it had chosen its work,
                // rather than while doing it. Without this the row would
                // report a clean run over nothing.
                if (!isset($task->payload['queued'])) {
                    $task->markFailed(__('workspace.reextraction_failed'));

                    return;
                }

                $task->markCompleted([
                    // The loader job is in the batch too, so it counts itself.
                    'attachments' => max(0, $batch->totalJobs - 1),
                    'failed' => $batch->failedJobs,
                ]);
            })
            ->dispatch();

        $task->update(['payload' => ['batch_id' => $batch->id] + ($task->payload ?? [])]);

        return $task;
    }

    /**
     * How many attachments the sweep will cover.
     *
     * Counted before the lock is taken so that a run matching nothing is
     * refused rather than occupying the workspace's one re-extraction slot to
     * do no work.
     *
     * @param Workspace $workspace The workspace whose attachments are re-read.
     * @param ReextractionFilter $filter Which of the workspace's attachments to cover.
     * @param int|null $limit Stop after this many attachments, or null for all of them.
     *
     * @return int The number of attachments that would be queued.
     */
    public function count(Workspace $workspace, ReextractionFilter $filter, ?int $limit = null): int
    {
        $matching = $filter->apply($workspace)->count();

        return $limit === null ? $matching : min($matching, $limit);
    }
}
