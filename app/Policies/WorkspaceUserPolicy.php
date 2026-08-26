<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;

class WorkspaceUserPolicy
{
    public function viewAny(User $user, Workspace $workspace): bool
    {
        return $workspace->isAdmin($user);
    }

    public function view(User $user, WorkspaceUser $membership): bool
    {
        return $membership->workspace->isAdmin($user) || $membership->user_id === $user->id;
    }

    public function create(User $user, Workspace $workspace): bool
    {
        return $workspace->isAdmin($user);
    }

    public function update(User $user, WorkspaceUser $membership): bool
    {
        return $membership->workspace->isAdmin($user);
    }

    public function delete(User $user, WorkspaceUser $membership): bool
    {
        return $membership->workspace->isAdmin($user);
    }
}
