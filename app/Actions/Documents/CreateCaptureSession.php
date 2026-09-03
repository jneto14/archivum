<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Models\Document;
use App\Models\DocumentCaptureSession;
use App\Models\User;

class CreateCaptureSession
{
    /**
     * Open a new phone pairing session for a document.
     *
     * At most one session is ever `Active` for a document at a time. Starting
     * a new one cancels whichever was still open rather than letting both
     * exist — two live QR codes for the same document would leave whoever
     * scans the older one uploading into a session the desktop has already
     * moved past.
     *
     * @param Document $document The document photos will be attached to.
     * @param User $user The user starting the session, recorded as the uploader of whatever the phone sends.
     *
     * @return DocumentCaptureSession The newly created, active session.
     */
    public function handle(Document $document, User $user): DocumentCaptureSession
    {
        $document->activeCaptureSession?->cancel();

        return DocumentCaptureSession::query()->create([
            'document_id' => $document->id,
            'created_by' => $user->id,
            'expires_at' => now()->addMinutes((int) config('archivum.capture.session_ttl_minutes')),
        ]);
    }
}
