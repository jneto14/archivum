<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\LogsWorkspaceActivity;
use App\Enums\WorkspaceRole;
use Database\Factories\WorkspaceUserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $user_id
 * @property WorkspaceRole $role
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['workspace_id', 'user_id', 'role'])]
class WorkspaceUser extends Model
{
    /** @use HasFactory<WorkspaceUserFactory> */
    use HasFactory, HasUuids, LogsWorkspaceActivity;

    protected $table = 'workspace_user';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => WorkspaceRole::class,
        ];
    }

    /**
     * @return LogOptions Logs role changes under the 'workspace_member' log name.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('workspace_member')
            ->logOnly(['role'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return string|null This membership's workspace id.
     */
    protected function resolveActivityWorkspaceId(): ?string
    {
        return $this->workspace_id;
    }

    /**
     * @return string|null The member's name.
     */
    protected function resolveActivityLabel(): ?string
    {
        return $this->user?->name;
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
}
