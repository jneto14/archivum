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
     * @param  User  $user  The acting user.
     * @param  Workspace  $workspace  The workspace whose documents are being listed.
     * @return bool True if $user is a member of $workspace.
     */
    public function viewAny(User $user, Workspace $workspace): bool
    {
        return $workspace->isMember($user);
    }

    /**
     * Determine whether the user may view the given document.
     *
     * @param  User  $user  The acting user.
     * @param  Document  $document  The document being viewed.
     * @return bool True if $user is a member of $document's workspace.
     */
    public function view(User $user, Document $document): bool
    {
        return $document->workspace->isMember($user);
    }

    /**
     * Determine whether the user may create a document in the given workspace.
     *
     * @param  User  $user  The acting user.
     * @param  Workspace  $workspace  The workspace the document would be created in.
     * @return bool True if $user is a member of $workspace.
     */
    public function create(User $user, Workspace $workspace): bool
    {
        return $workspace->isMember($user);
    }

    /**
     * Workspace admins can update any document; other members may only
     * update documents they created.
     *
     * @param  User  $user  The acting user.
     * @param  Document  $document  The document being updated.
     * @return bool True if $user is an admin of $document's workspace, or created $document.
     */
    public function update(User $user, Document $document): bool
    {
        return $document->workspace->isAdmin($user) || $document->created_by === $user->id;
    }

    /**
     * Workspace admins can delete any document; other members may only
     * delete documents they created.
     *
     * @param  User  $user  The acting user.
     * @param  Document  $document  The document being deleted.
     * @return bool True if $user is an admin of $document's workspace, or created $document.
     */
    public function delete(User $user, Document $document): bool
    {
        return $document->workspace->isAdmin($user) || $document->created_by === $user->id;
    }
}
