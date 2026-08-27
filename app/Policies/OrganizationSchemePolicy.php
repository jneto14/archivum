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
     * @param  Workspace  $workspace  The workspace whose schemes are being listed.
     */
    public function viewAny(User $user, Workspace $workspace): bool
    {
        return $workspace->isMember($user);
    }

    /**
     * Determine whether the user may view the given organization scheme.
     */
    public function view(User $user, OrganizationScheme $scheme): bool
    {
        return $scheme->workspace->isMember($user);
    }

    /**
     * Only workspace admins may create organization schemes.
     *
     * @param  Workspace  $workspace  The workspace the scheme would be created in.
     */
    public function create(User $user, Workspace $workspace): bool
    {
        return $workspace->isAdmin($user);
    }

    /**
     * Only workspace admins may update an organization scheme.
     */
    public function update(User $user, OrganizationScheme $scheme): bool
    {
        return $scheme->workspace->isAdmin($user);
    }

    /**
     * Only workspace admins may delete an organization scheme.
     */
    public function delete(User $user, OrganizationScheme $scheme): bool
    {
        return $scheme->workspace->isAdmin($user);
    }
}
