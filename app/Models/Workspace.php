<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WorkspaceRole;
use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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

    /**
     * @return HasOne<WorkspaceLimit, $this>
     */
    public function limits(): HasOne
    {
        return $this->hasOne(WorkspaceLimit::class);
    }

    /**
     * Determine whether the given user is a member of this workspace, i.e. has a
     * corresponding workspace_user pivot record regardless of role.
     *
     * @param User $user The user to check membership for.
     *
     * @return bool True if $user has a workspace_user pivot record for this workspace.
     */
    public function isMember(User $user): bool
    {
        return WorkspaceUser::query()
            ->where('workspace_id', $this->id)
            ->where('user_id', $user->id)
            ->exists();
    }

    /**
     * Determine whether the given user holds the Admin role in this workspace.
     *
     * @param User $user The user to check the admin role for.
     *
     * @return bool True if $user's membership in this workspace has the Admin role.
     */
    public function isAdmin(User $user): bool
    {
        return WorkspaceUser::query()
            ->where('workspace_id', $this->id)
            ->where('user_id', $user->id)
            ->where('role', WorkspaceRole::Admin)
            ->exists();
    }

    /**
     * Determine whether the given user may manage this workspace as an admin
     * would — either because they hold the Admin role within it, or because
     * they're a platform admin with full access to every workspace regardless
     * of their own membership.
     *
     * @param User $user The user to check.
     *
     * @return bool True if $user is an admin of this workspace, or a platform admin.
     */
    public function isManageableBy(User $user): bool
    {
        return $this->isAdmin($user) || $user->is_platform_admin;
    }

    /**
     * Count how many members currently hold the Admin role in this workspace.
     *
     * @return int The number of workspace_user records with the Admin role.
     */
    public function adminsCount(): int
    {
        return WorkspaceUser::query()
            ->where('workspace_id', $this->id)
            ->where('role', WorkspaceRole::Admin)
            ->count();
    }

    /**
     * Determine whether removing the given user from this workspace would leave it
     * with no remaining admins, based on whether they are currently its sole admin.
     *
     * @param User $user The user being considered for removal.
     *
     * @return bool True if the user currently holds the Admin role and is the workspace's only admin.
     */
    public function wouldRemoveLastAdmin(User $user): bool
    {
        return $this->isAdmin($user) && $this->adminsCount() === 1;
    }
}
