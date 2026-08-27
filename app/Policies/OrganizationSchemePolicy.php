<?php

namespace App\Policies;

use App\Models\OrganizationScheme;
use App\Models\User;
use App\Models\Workspace;

class OrganizationSchemePolicy
{
    /**
     * Determine whether the user may list organization schemes in the given workspace.
     *
     * @param  User  $user  The acting user.
     * @param  Workspace  $workspace  The workspace whose schemes are being listed.
     * @return bool True if $user is a member of $workspace.
     */
    public function viewAny(User $user, Workspace $workspace): bool
    {
        return $workspace->isMember($user);
    }

    /**
     * Determine whether the user may view the given organization scheme.
     *
     * @param  User  $user  The acting user.
     * @param  OrganizationScheme  $scheme  The scheme being viewed.
     * @return bool True if $user is a member of $scheme's workspace.
     */
    public function view(User $user, OrganizationScheme $scheme): bool
    {
        return $scheme->workspace->isMember($user);
    }

    /**
     * Only workspace admins may create organization schemes.
     *
     * @param  User  $user  The acting user.
     * @param  Workspace  $workspace  The workspace the scheme would be created in.
     * @return bool True if $user is an admin of $workspace.
     */
    public function create(User $user, Workspace $workspace): bool
    {
        return $workspace->isAdmin($user);
    }

    /**
     * Only workspace admins may update an organization scheme.
     *
     * @param  User  $user  The acting user.
     * @param  OrganizationScheme  $scheme  The scheme being updated.
     * @return bool True if $user is an admin of $scheme's workspace.
     */
    public function update(User $user, OrganizationScheme $scheme): bool
    {
        return $scheme->workspace->isAdmin($user);
    }

    /**
     * Only workspace admins may delete an organization scheme.
     *
     * @param  User  $user  The acting user.
     * @param  OrganizationScheme  $scheme  The scheme being deleted.
     * @return bool True if $user is an admin of $scheme's workspace.
     */
    public function delete(User $user, OrganizationScheme $scheme): bool
    {
        return $scheme->workspace->isAdmin($user);
    }
}
