<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\User;

class DocumentAttachmentPolicy
{
    /**
     * Determine whether the user may attach a file to the given document.
     *
     * @param  User  $user  The acting user.
     * @param  Document  $document  The document the attachment would belong to.
     * @return bool True if $user is a member of $document's workspace.
     */
    public function create(User $user, Document $document): bool
    {
        return $document->workspace->isMember($user);
    }

    /**
     * Determine whether the user may view (download) the given attachment.
     *
     * @param  User  $user  The acting user.
     * @param  DocumentAttachment  $attachment  The attachment being viewed.
     * @return bool True if $user is a member of the attachment's document's workspace.
     */
    public function view(User $user, DocumentAttachment $attachment): bool
    {
        return $attachment->document->workspace->isMember($user);
    }

    /**
     * Workspace admins can delete any attachment; other members may only
     * delete attachments they uploaded themselves.
     *
     * @param  User  $user  The acting user.
     * @param  DocumentAttachment  $attachment  The attachment being deleted.
     * @return bool True if $user is an admin of the attachment's document's workspace, or uploaded $attachment.
     */
    public function delete(User $user, DocumentAttachment $attachment): bool
    {
        return $attachment->document->workspace->isAdmin($user) || $attachment->uploaded_by === $user->id;
    }
}
