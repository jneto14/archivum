<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;

class WorkspacePolicy
{
    /**
     * Any authenticated user may list workspaces; the results are scoped
     * to their memberships at the query level, not by this gate.
     *
     * @param User $user The acting user.
     *
     * @return bool Always true.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user may view the given workspace.
     *
     * @param User $user The acting user.
     * @param Workspace $workspace The workspace being viewed.
     *
     * @return bool True if $user is a member of $workspace.
     */
    public function view(User $user, Workspace $workspace): bool
    {
        return $workspace->isMember($user);
    }

    /**
     * Workspace creation is only allowed when multi-workspace mode is
     * enabled; in single-workspace mode no user may create additional ones.
     *
     * @param User $user The acting user.
     *
     * @return bool True if the `archivum.multi_workspace_enabled` config is enabled.
     */
    public function create(User $user): bool
    {
        return (bool) config('archivum.multi_workspace_enabled');
    }

    /**
     * Only workspace admins may update workspace settings.
     *
     * @param User $user The acting user.
     * @param Workspace $workspace The workspace being updated.
     *
     * @return bool True if $user is an admin of $workspace.
     */
    public function update(User $user, Workspace $workspace): bool
    {
        return $workspace->isAdmin($user);
    }

    /**
     * Only workspace admins may view usage and limits for the given workspace.
     *
     * @param User $user The acting user.
     * @param Workspace $workspace The workspace whose usage is being viewed.
     *
     * @return bool True if $user is an admin of $workspace.
     */
    public function viewUsage(User $user, Workspace $workspace): bool
    {
        return $workspace->isAdmin($user);
    }

    /**
     * Only workspace admins may delete a workspace.
     *
     * @param User $user The acting user.
     * @param Workspace $workspace The workspace being deleted.
     *
     * @return bool True if $user is an admin of $workspace.
     */
    public function delete(User $user, Workspace $workspace): bool
    {
        return $workspace->isAdmin($user);
    }
}
