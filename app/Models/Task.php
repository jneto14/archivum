<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $user_id
 * @property TaskType $type
 * @property TaskStatus $status
 * @property array<string, mixed>|null $payload
 * @property array<string, mixed>|null $result
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['workspace_id', 'user_id', 'type', 'status', 'payload', 'result', 'started_at', 'finished_at'])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TaskType::class,
            'status' => TaskStatus::class,
            'payload' => 'array',
            'result' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Move this task into the "processing" state and record when it started.
     *
     * @return void No return value; persists the status change as a side effect.
     */
    public function markProcessing(): void
    {
        $this->update(['status' => TaskStatus::Processing, 'started_at' => now()]);
    }

    /**
     * Mark this task as successfully completed.
     *
     * @param array<string, mixed> $result The task's output (e.g. the exported file's disk and path).
     *
     * @return void No return value; persists the status change as a side effect.
     */
    public function markCompleted(array $result): void
    {
        $this->update(['status' => TaskStatus::Completed, 'result' => $result, 'finished_at' => now()]);
    }

    /**
     * Add to the count of units this task has got through.
     *
     * Written straight into the payload's JSON rather than read-modify-written
     * through the model, because a sweep's chunks may be running on several
     * workers at once and two of them finishing together would otherwise each
     * write the count they read before the other. One statement, so the
     * database resolves it.
     *
     * @param int $by How many more units are done.
     *
     * @return void No return value; updates the payload as a side effect.
     */
    public function advanceProgress(int $by): void
    {
        if ($by < 1) {
            return;
        }

        DB::update(
            <<<'SQL'
                update tasks
                set payload = json_set(
                    coalesce(payload, '{}'),
                    '$.processed',
                    -- Cast, or the arithmetic makes it a double and the
                    -- payload comes back carrying `3.0` where it means 3.
                    cast(coalesce(json_extract(payload, '$.processed'), 0) + ? as signed)
                )
                where id = ?
                SQL,
            [$by, $this->getKey()],
        );
    }

    /**
     * Mark this task as failed.
     *
     * @param string $message A human-readable description of what went wrong.
     *
     * @return void No return value; persists the status change as a side effect.
     */
    public function markFailed(string $message): void
    {
        $this->update(['status' => TaskStatus::Failed, 'result' => ['error' => $message], 'finished_at' => now()]);
    }
}
