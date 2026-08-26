<?php

namespace App\Actions\Workspace;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Validation\ValidationException;

class AddWorkspaceUser
{
    /**
     * Add a user to a Workspace with the given role.
     */
    public function handle(Workspace $workspace, User $user, WorkspaceRole $role): WorkspaceUser
    {
        if ($workspace->isMember($user)) {
            throw ValidationException::withMessages([
                'email' => 'This user is already a member of this workspace.',
            ]);
        }

        return WorkspaceUser::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);
    }
}
