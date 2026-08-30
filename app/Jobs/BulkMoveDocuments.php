<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Organization\MigrateNodeDocuments;
use App\Models\OrganizationNode;
use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\Support\CauserResolver;
use Throwable;

/**
 * Queues the migration of every document under one organization node to
 * another, off the request cycle since a node can hold a large number of
 * documents. Tracks its progress on the given Task row and releases the
 * concurrency lock the dispatching action acquired, whether the move
 * succeeds or fails.
 */
class BulkMoveDocuments implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param Task $task The task record tracking this migration.
     * @param OrganizationNode $source The node documents are currently located at.
     * @param OrganizationNode $target The node documents are moved onto.
     * @param string $lockOwner The owner token of the Cache::lock() acquired before this job was dispatched.
     */
    public function __construct(
        public readonly Task $task,
        public readonly OrganizationNode $source,
        public readonly OrganizationNode $target,
        public readonly string $lockOwner,
    ) {}

    /**
     * @param MigrateNodeDocuments $action Resolved from the container by the queue worker.
     * @param CauserResolver $causerResolver Used to attribute the resulting activity-log
     *                                       entries to the user who triggered this job, since
     *                                       there's no authenticated request inside a queue worker.
     *
     * @return void No return value; documents are relocated and the Task row updated as a side effect.
     */
    public function handle(MigrateNodeDocuments $action, CauserResolver $causerResolver): void
    {
        $this->task->markProcessing();

        try {
            $causerResolver->withCauser(
                $this->task->user,
                fn () => $action->handle($this->source, $this->target),
            );

            $this->task->markCompleted([
                'source_node_id' => $this->source->id,
                'target_node_id' => $this->target->id,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            $this->task->markFailed($exception->getMessage());
        } finally {
            Cache::restoreLock($this->task->type->lockKey($this->task->workspace_id), $this->lockOwner)->release();
        }
    }
}
