<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\User;

class DocumentAttachmentPolicy
{
    public function create(User $user, Document $document): bool
    {
        return $document->workspace->isMember($user);
    }

    public function view(User $user, DocumentAttachment $attachment): bool
    {
        return $attachment->document->workspace->isMember($user);
    }

    public function delete(User $user, DocumentAttachment $attachment): bool
    {
        return $attachment->document->workspace->isAdmin($user) || $attachment->uploaded_by === $user->id;
    }
}
