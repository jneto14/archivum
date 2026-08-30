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
use Throwable;

/**
 * Queues the migration of every document under one organization node to
 * another, off the request cycle since a node can hold a large number of
 * documents. Tracks its progress on the given Task row.
 */
class BulkMoveDocuments implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param Task $task The task record tracking this migration.
     * @param OrganizationNode $source The node documents are currently located at.
     * @param OrganizationNode $target The node documents are moved onto.
     */
    public function __construct(
        public readonly Task $task,
        public readonly OrganizationNode $source,
        public readonly OrganizationNode $target,
    ) {}

    /**
     * @param MigrateNodeDocuments $action Resolved from the container by the queue worker.
     *
     * @return void No return value; documents are relocated and the Task row updated as a side effect.
     */
    public function handle(MigrateNodeDocuments $action): void
    {
        $this->task->markProcessing();

        try {
            $action->handle($this->source, $this->target);

            $this->task->markCompleted([
                'source_node_id' => $this->source->id,
                'target_node_id' => $this->target->id,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            $this->task->markFailed($exception->getMessage());
        }
    }
}
