<?php

declare(strict_types=1);

namespace App\Actions\Organization;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\BulkMoveDocuments;
use App\Models\OrganizationNode;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use LogicException;

class StartBulkDocumentMove
{
    public function __construct(private readonly MigrateNodeDocuments $migrateNodeDocuments) {}

    /**
     * Queue the relocation of every document at $source onto $target, guarded by an
     * atomic lock so two bulk moves can't run concurrently for the same workspace.
     *
     * @param OrganizationNode $source The node whose documents are being moved.
     * @param OrganizationNode $target The node the documents are moved onto.
     * @param User $user The user who triggered the move.
     *
     * @return Task The newly created, queued task.
     *
     * @throws ValidationException If $target cannot hold every document being moved, or a bulk document move is already running for the source node's workspace.
     */
    public function handle(OrganizationNode $source, OrganizationNode $target, User $user): Task
    {
        // Asked before the lock and the task, so a migration that cannot fit is
        // refused in the dialog rather than by a task that fails minutes later.
        $this->migrateNodeDocuments->assertTargetHasRoom($source, $target);

        $workspaceId = $source->level->scheme->workspace_id;

        // `lockKey()` is nullable because attachment text extraction has no
        // per-workspace exclusivity; a bulk move always does.
        $lockKey = TaskType::BulkDocumentMove->lockKey($workspaceId)
            ?? throw new LogicException('A bulk document move must have a workspace lock.');

        $lock = Cache::lock($lockKey, 600);

        if (!$lock->get()) {
            throw ValidationException::withMessages([
                'task' => __('workspace.bulk_move_already_running'),
            ]);
        }

        $task = Task::query()->create([
            'workspace_id' => $workspaceId,
            'user_id' => $user->id,
            'type' => TaskType::BulkDocumentMove,
            'status' => TaskStatus::Queued,
            'payload' => ['source_node_id' => $source->id, 'target_node_id' => $target->id],
        ]);

        BulkMoveDocuments::dispatch($task, $source, $target, $lock->owner());

        return $task;
    }
}
