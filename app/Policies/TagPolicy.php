<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;

class TagPolicy
{
    /**
     * Determine whether the user may create a tag in the given workspace.
     *
     * @param  Workspace  $workspace  The workspace the tag would be created in.
     */
    public function create(User $user, Workspace $workspace): bool
    {
        return $workspace->isMember($user);
    }
}
