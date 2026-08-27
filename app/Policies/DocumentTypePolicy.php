<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;

class DocumentTypePolicy
{
    /**
     * Only workspace admins may define new document types.
     *
     * @param  Workspace  $workspace  The workspace the document type would be created in.
     */
    public function create(User $user, Workspace $workspace): bool
    {
        return $workspace->isAdmin($user);
    }
}
