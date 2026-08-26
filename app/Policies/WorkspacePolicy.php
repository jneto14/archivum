<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;

class WorkspacePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Workspace $workspace): bool
    {
        return $workspace->isMember($user);
    }

    public function create(User $user): bool
    {
        return (bool) config('archivum.multi_workspace_enabled');
    }

    public function update(User $user, Workspace $workspace): bool
    {
        return $workspace->isAdmin($user);
    }

    public function delete(User $user, Workspace $workspace): bool
    {
        return $workspace->isAdmin($user);
    }
}
