<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;

class DocumentTypePolicy
{
    public function create(User $user, Workspace $workspace): bool
    {
        return $workspace->isAdmin($user);
    }
}
