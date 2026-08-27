<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;

class WorkspacePolicy
{
    /**
     * Any authenticated user may list workspaces; the results are scoped
     * to their memberships at the query level, not by this gate.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Workspace $workspace): bool
    {
        return $workspace->isMember($user);
    }

    /**
     * Workspace creation is only allowed when multi-workspace mode is
     * enabled; in single-workspace mode no user may create additional ones.
     */
    public function create(User $user): bool
    {
        return (bool) config('archivum.multi_workspace_enabled');
    }

    public function update(User $user, Workspace $workspace): bool
    {
        return $workspace->isAdmin($user);
    }

    public function viewUsage(User $user, Workspace $workspace): bool
    {
        return $workspace->isAdmin($user);
    }

    public function delete(User $user, Workspace $workspace): bool
    {
        return $workspace->isAdmin($user);
    }
}
