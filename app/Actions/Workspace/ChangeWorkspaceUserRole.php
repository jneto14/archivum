<?php

namespace App\Actions\Workspace;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Validation\ValidationException;

class ChangeWorkspaceUserRole
{
    /**
     * Change a member's role within a Workspace.
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
                'role' => __('Cannot demote the last admin of a workspace.'),
            ]);
        }

        $membership->update([
            'role' => $role,
        ]);

        return $membership;
    }
}
