<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class ChangeWorkspaceUserRole
{
    /**
     * Change a member's role within a Workspace.
     *
     * @param Workspace $workspace The workspace the membership belongs to.
     * @param User $user The user whose role is being changed.
     * @param WorkspaceRole $role The new role to assign.
     *
     * @return WorkspaceUser The membership record (unchanged if $role already matches the current role).
     *
     * @throws ModelNotFoundException If $user is not a member of $workspace.
     * @throws ValidationException If demoting $user to WorkspaceRole::User would remove the workspace's last admin.
     */
    public function handle(Workspace $workspace, User $user, WorkspaceRole $role): WorkspaceUser
    {
        $membership = WorkspaceUser::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        if ($membership->role === $role) {
            return $membership;
        }

        if ($role === WorkspaceRole::User && $workspace->wouldRemoveLastAdmin($user)) {
            throw ValidationException::withMessages([
                'role' => __('workspace.cannot_demote_last_admin'),
            ]);
        }

        $membership->update([
            'role' => $role,
        ]);

        return $membership;
    }
}
