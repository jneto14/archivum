<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IntakeLabelStatus;
use Database\Factories\IntakeLabelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A phrase this workspace's own documents were seen writing in front of a
 * value, offered to an admin as a label the reader could use.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $kind
 * @property string $label
 * @property IntakeLabelStatus $status
 * @property int $support
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['workspace_id', 'kind', 'label', 'status', 'support'])]
class IntakeLabel extends Model
{
    /** @use HasFactory<IntakeLabelFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string> Casts the status to its enum.
     */
    protected function casts(): array
    {
        return ['status' => IntakeLabelStatus::class];
    }

    /**
     * @return BelongsTo<Workspace, $this> The workspace whose vocabulary this belongs to.
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @param Builder<IntakeLabel> $query The query to constrain.
     *
     * @return void Limits it to labels the workspace said yes to.
     */
    public function scopeAccepted(Builder $query): void
    {
        $query->where('status', IntakeLabelStatus::Accepted);
    }

    /**
     * @param Builder<IntakeLabel> $query The query to constrain.
     *
     * @return void Limits it to candidates nobody has answered yet.
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', IntakeLabelStatus::Pending);
    }
}
