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
     * @param  Document  $document  The document the attachment would belong to.
     */
    public function create(User $user, Document $document): bool
    {
        return $document->workspace->isMember($user);
    }

    /**
     * Determine whether the user may view (download) the given attachment.
     */
    public function view(User $user, DocumentAttachment $attachment): bool
    {
        return $attachment->document->workspace->isMember($user);
    }

    /**
     * Workspace admins can delete any attachment; other members may only
     * delete attachments they uploaded themselves.
     */
    public function delete(User $user, DocumentAttachment $attachment): bool
    {
        return $attachment->document->workspace->isAdmin($user) || $attachment->uploaded_by === $user->id;
    }
}
