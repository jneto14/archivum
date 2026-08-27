<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;

class DocumentTypePolicy
{
    /**
     * Only workspace admins may define new document types.
     *
     * @param User $user The acting user.
     * @param Workspace $workspace The workspace the document type would be created in.
     *
     * @return bool True if $user is an admin of $workspace.
     */
    public function create(User $user, Workspace $workspace): bool
    {
        return $workspace->isAdmin($user);
    }
}
