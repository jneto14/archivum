<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;

class DocumentPolicy
{
    /**
     * Determine whether the user may list documents in the given workspace.
     *
     * @param  Workspace  $workspace  The workspace whose documents are being listed.
     */
    public function viewAny(User $user, Workspace $workspace): bool
    {
        return $workspace->isMember($user);
    }

    /**
     * Determine whether the user may view the given document.
     */
    public function view(User $user, Document $document): bool
    {
        return $document->workspace->isMember($user);
    }

    /**
     * Determine whether the user may create a document in the given workspace.
     *
     * @param  Workspace  $workspace  The workspace the document would be created in.
     */
    public function create(User $user, Workspace $workspace): bool
    {
        return $workspace->isMember($user);
    }

    /**
     * Workspace admins can update any document; other members may only
     * update documents they created.
     */
    public function update(User $user, Document $document): bool
    {
        return $document->workspace->isAdmin($user) || $document->created_by === $user->id;
    }

    /**
     * Workspace admins can delete any document; other members may only
     * delete documents they created.
     */
    public function delete(User $user, Document $document): bool
    {
        return $document->workspace->isAdmin($user) || $document->created_by === $user->id;
    }
}
