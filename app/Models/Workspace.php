<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name'])]
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory, HasUuids;

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')
            ->withPivot(['id', 'role'])
            ->withTimestamps();
    }

    public function isMember(User $user): bool
    {
        return WorkspaceUser::query()
            ->where('workspace_id', $this->id)
            ->where('user_id', $user->id)
            ->exists();
    }

    public function isAdmin(User $user): bool
    {
        return WorkspaceUser::query()
            ->where('workspace_id', $this->id)
            ->where('user_id', $user->id)
            ->where('role', WorkspaceRole::Admin)
            ->exists();
    }

    public function adminsCount(): int
    {
        return WorkspaceUser::query()
            ->where('workspace_id', $this->id)
            ->where('role', WorkspaceRole::Admin)
            ->count();
    }

    public function wouldRemoveLastAdmin(User $user): bool
    {
        return $this->isAdmin($user) && $this->adminsCount() === 1;
    }
}
