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
     * Workspace creation is restricted to platform admins; they pass this
     * check via the Gate::before() bypass, so no other user may create one.
     * Whether multi-workspace mode is enabled at all is an instance-wide
     * flag, not a per-user authorization concern, so it's checked at the
     * controller entry point (see WorkspaceController::store()) rather than
     * here — the same convention used by WorkspaceController::index().
     *
     * @param User $user The acting user.
     *
     * @return bool Always false; only a platform admin (via Gate::before()) may create a workspace.
     */
    public function create(User $user): bool
    {
        return false;
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
     * Workspace resource limits are an operator-level control, not something
     * a workspace's own admin manages — the "Usage & limits" page is purely
     * informational for them. Only platform admins may edit limits; they
     * pass this check via the Gate::before() bypass, so no workspace admin
     * may edit limits for their own workspace.
     *
     * @param User $user The acting user.
     * @param Workspace $workspace The workspace whose limits are being edited.
     *
     * @return bool Always false; only a platform admin (via Gate::before()) may edit workspace limits.
     */
    public function updateLimits(User $user, Workspace $workspace): bool
    {
        return false;
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
