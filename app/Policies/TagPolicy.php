<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;

class TagPolicy
{
    public function create(User $user, Workspace $workspace): bool
    {
        return $workspace->isMember($user);
    }
}
