<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;

class WorkspaceUserPolicy
{
    /**
     * Only workspace admins may list the workspace's memberships.
     *
     * @param  Workspace  $workspace  The workspace whose memberships are being listed.
     */
    public function viewAny(User $user, Workspace $workspace): bool
    {
        return $workspace->isAdmin($user);
    }

    /**
     * Workspace admins can view any membership; other members may only
     * view their own.
     *
     * @param  WorkspaceUser  $membership  The membership record being viewed.
     */
    public function view(User $user, WorkspaceUser $membership): bool
    {
        return $membership->workspace->isAdmin($user) || $membership->user_id === $user->id;
    }

    /**
     * Only workspace admins may add a member to the workspace.
     *
     * @param  Workspace  $workspace  The workspace the membership would be created in.
     */
    public function create(User $user, Workspace $workspace): bool
    {
        return $workspace->isAdmin($user);
    }

    /**
     * Only workspace admins may change a member's role.
     *
     * @param  WorkspaceUser  $membership  The membership record being updated.
     */
    public function update(User $user, WorkspaceUser $membership): bool
    {
        return $membership->workspace->isAdmin($user);
    }

    /**
     * Only workspace admins may remove a member from the workspace.
     *
     * @param  WorkspaceUser  $membership  The membership record being removed.
     */
    public function delete(User $user, WorkspaceUser $membership): bool
    {
        return $membership->workspace->isAdmin($user);
    }
}
