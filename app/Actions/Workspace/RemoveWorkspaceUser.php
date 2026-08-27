<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Validation\ValidationException;

class RemoveWorkspaceUser
{
    /**
     * Remove a user from a Workspace.
     *
     * @param Workspace $workspace The workspace to remove the user from.
     * @param User $user The user being removed.
     *
     * @return void No return value; the membership is deleted as a side effect.
     *
     * @throws ValidationException If removing $user would leave the workspace without an admin.
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
