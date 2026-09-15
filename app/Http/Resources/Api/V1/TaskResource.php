<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A piece of work the archive is doing, or has done, in the background.
 *
 * @mixin Task
 */
class TaskResource extends JsonResource
{
    /**
     * @param Request $request The incoming request.
     *
     * @return array<string, mixed> The task's public attributes.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'triggered_by' => $this->whenLoaded('user', fn (): ?array => $this->user === null ? null : [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
            'subject' => $this->payload['filename'] ?? null,
            'progress' => $this->progress(),
            'error' => is_array($this->result) ? ($this->result['error'] ?? null) : null,
            'result_available' => $this->type === TaskType::DocumentExport
                && $this->status === TaskStatus::Completed
                && is_array($this->result)
                && !isset($this->result['error']),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * How far a bulk re-extraction has got.
     *
     * Counted in attachments rather than in queued jobs: a job is a chunk of
     * them, so "8 of 200" would be a number about the queue rather than about
     * the archive. Capped at the total settled before the sweep started,
     * because a chunk that runs out of clock hands its remainder back as a
     * fresh chunk — the jobs can outnumber the attachments even though the
     * attachments do not.
     *
     * @return array{processed: int, total: int}|null The sweep's progress, or null when this task is not a running sweep.
     */
    private function progress(): ?array
    {
        if ($this->type !== TaskType::BulkAttachmentTextExtraction || $this->status !== TaskStatus::Processing) {
            return null;
        }

        $total = (int) ($this->payload['total'] ?? 0);

        if ($total < 1) {
            return null;
        }

        return [
            'processed' => min($total, (int) ($this->payload['processed'] ?? 0)),
            'total' => $total,
        ];
    }
}
