<?php

namespace App\Policies;

use App\Models\OrganizationScheme;
use App\Models\User;
use App\Models\Workspace;

class OrganizationSchemePolicy
{
    public function viewAny(User $user, Workspace $workspace): bool
    {
        return $workspace->isMember($user);
    }

    public function view(User $user, OrganizationScheme $scheme): bool
    {
        return $scheme->workspace->isMember($user);
    }

    public function create(User $user, Workspace $workspace): bool
    {
        return $workspace->isAdmin($user);
    }

    public function update(User $user, OrganizationScheme $scheme): bool
    {
        return $scheme->workspace->isAdmin($user);
    }

    public function delete(User $user, OrganizationScheme $scheme): bool
    {
        return $scheme->workspace->isAdmin($user);
    }
}
