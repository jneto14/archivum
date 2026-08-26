<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;

class DocumentPolicy
{
    public function viewAny(User $user, Workspace $workspace): bool
    {
        return $workspace->isMember($user);
    }

    public function view(User $user, Document $document): bool
    {
        return $document->workspace->isMember($user);
    }

    public function create(User $user, Workspace $workspace): bool
    {
        return $workspace->isMember($user);
    }

    public function update(User $user, Document $document): bool
    {
        return $document->workspace->isAdmin($user) || $document->created_by === $user->id;
    }

    public function delete(User $user, Document $document): bool
    {
        return $document->workspace->isAdmin($user) || $document->created_by === $user->id;
    }
}
