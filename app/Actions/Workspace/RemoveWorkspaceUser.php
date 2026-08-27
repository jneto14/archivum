<?php

namespace App\Actions\Workspace;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Validation\ValidationException;

class RemoveWorkspaceUser
{
    /**
     * Remove a user from a Workspace.
     */
    public function handle(Workspace $workspace, User $user): void
    {
        if ($workspace->wouldRemoveLastAdmin($user)) {
            throw ValidationException::withMessages([
                'user' => __('workspace.cannot_remove_last_admin'),
            ]);
        }

        WorkspaceUser::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->delete();
    }
}
