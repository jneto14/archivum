<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Document;
use App\Models\DocumentCaptureSession;
use App\Models\User;

class DocumentCaptureSessionPolicy
{
    /**
     * Determine whether the user may start a phone pairing session for the
     * given document. Same rule as attaching a file directly — a capture
     * session is just another way to do that.
     *
     * @param User $user The acting user.
     * @param Document $document The document a session would be opened for.
     *
     * @return bool True if $user is a member of $document's workspace.
     */
    public function create(User $user, Document $document): bool
    {
        return $document->workspace->isMember($user);
    }

    /**
     * Determine whether the user may see this session's live status (the
     * desktop's poll) or its QR code.
     *
     * @param User $user The acting user.
     * @param DocumentCaptureSession $captureSession The session being viewed.
     *
     * @return bool True if $user is a member of the session's document's workspace.
     */
    public function view(User $user, DocumentCaptureSession $captureSession): bool
    {
        return $captureSession->document->workspace->isMember($user);
    }

    /**
     * Determine whether the user may end this session early from the desktop
     * side. Same membership rule as `view` — cancelling a pairing session
     * carries none of the risk deleting someone else's upload does.
     *
     * @param User $user The acting user.
     * @param DocumentCaptureSession $captureSession The session being cancelled.
     *
     * @return bool True if $user is a member of the session's document's workspace.
     */
    public function cancel(User $user, DocumentCaptureSession $captureSession): bool
    {
        return $captureSession->document->workspace->isMember($user);
    }
}
