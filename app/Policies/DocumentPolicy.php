<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;

class DocumentPolicy
{
    /**
     * Determine whether the user may list documents in the given workspace.
     *
     * @param User $user The acting user.
     * @param Workspace $workspace The workspace whose documents are being listed.
     *
     * @return bool True if $user is a member of $workspace.
     */
    public function viewAny(User $user, Workspace $workspace): bool
    {
        return $workspace->isMember($user);
    }

    /**
     * Determine whether the user may view the given document.
     *
     * @param User $user The acting user.
     * @param Document $document The document being viewed.
     *
     * @return bool True if $user is a member of $document's workspace.
     */
    public function view(User $user, Document $document): bool
    {
        return $document->workspace->isMember($user);
    }

    /**
     * Determine whether the user may create a document in the given workspace.
     *
     * @param User $user The acting user.
     * @param Workspace $workspace The workspace the document would be created in.
     *
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
     * @param User $user The acting user.
     * @param Document $document The document being updated.
     *
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
     * @param User $user The acting user.
     * @param Document $document The document being deleted.
     *
     * @return bool True if $user is an admin of $document's workspace, or created $document.
     */
    public function delete(User $user, Document $document): bool
    {
        return $document->workspace->isAdmin($user) || $document->created_by === $user->id;
    }

    /**
     * Whoever could have deleted it can take it back out of the trash.
     *
     * Restoring returns the archive to a state this user was already allowed
     * to put it in, so gating it more tightly than the deletion would leave
     * somebody able to make a mistake and unable to undo it.
     *
     * @param User $user The acting user.
     * @param Document $document The trashed document being restored.
     *
     * @return bool True if $user is an admin of $document's workspace, or created $document.
     */
    public function restore(User $user, Document $document): bool
    {
        return $this->delete($user, $document);
    }

    /**
     * Only workspace admins may destroy a document for good.
     *
     * Deliberately narrower than `delete`: trashing is reversible and purging
     * is not, and the person who should carry an irreversible decision about
     * the archive's contents is the one who administers it — not everyone who
     * happens to have filed the document.
     *
     * @param User $user The acting user.
     * @param Document $document The trashed document being destroyed.
     *
     * @return bool True if $user is an admin of $document's workspace.
     */
    public function forceDelete(User $user, Document $document): bool
    {
        return $document->workspace->isAdmin($user);
    }
}
