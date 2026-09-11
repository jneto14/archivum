<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use App\Actions\Documents\CreateCaptureSession;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\DocumentCaptureSession;
use App\Support\SignedLink;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CaptureSessionController extends Controller
{
    /**
     * Start a new "scan with your phone" pairing session for a document.
     * Nothing is flashed: the redirect below re-renders `documents/show`,
     * whose `activeCaptureSession` prop (see DocumentController::show()) picks
     * up the new session on its own, the same way any other create-then-
     * redirect does. Superseded sessions (see `CreateCaptureSession`) are
     * cancelled silently — there is nothing for the previous dialog instance
     * to be told, since starting a new one always means its QR code is gone.
     *
     * An optional `attachment` aims the session at one file: the phone
     * re-shoots that page and the photo replaces it, rather than arriving as
     * another attachment (ARC-124). It is validated as belonging to this
     * document, because a session is addressed by document and an id from
     * somewhere else would point the phone at a file this page never showed.
     *
     * @param Document $document The document photos will be attached to.
     * @param Request $request The incoming request, used to resolve the acting user and the optional attachment to re-shoot.
     * @param CreateCaptureSession $action Cancels any session already open for $document and creates a fresh one.
     *
     * @return RedirectResponse Redirect back to the previous page.
     *
     * @throws AuthorizationException If the current user cannot create a capture session for $document.
     * @throws ValidationException If `attachment` is given but is not one of $document's.
     */
    public function store(Document $document, Request $request, CreateCaptureSession $action): RedirectResponse
    {
        $this->authorize('create', [DocumentCaptureSession::class, $document]);

        $validated = $request->validate([
            'attachment' => [
                'nullable',
                'uuid',
                Rule::exists('document_attachments', 'id')
                    ->where('document_id', $document->id)
                    ->whereNull('deleted_at'),
            ],
        ]);

        $replaces = isset($validated['attachment'])
            ? DocumentAttachment::query()->where('id', $validated['attachment'])->firstOrFail()
            : null;

        $action->handle($document, $request->user(), $replaces);

        return back();
    }

    /**
     * Render this session's pairing link as a scannable QR code.
     *
     * The link itself is what a phone acts on; this endpoint only exists so
     * the authenticated desktop page can display it without a QR library in
     * the frontend bundle. Regenerated on every request rather than stored —
     * `URL::temporarySignedRoute()` is deterministic for the same session id
     * and expiry, so there is nothing to cache.
     *
     * @param Document $document The document the session must belong to.
     * @param DocumentCaptureSession $captureSession The session to link to.
     *
     * @return HttpResponse A PNG image of the QR code.
     *
     * @throws AuthorizationException If the current user cannot view $captureSession.
     */
    public function qrCode(Document $document, DocumentCaptureSession $captureSession): HttpResponse
    {
        abort_if($captureSession->document_id !== $document->id, 404);

        $this->authorize('view', $captureSession);

        $result = (new Builder(
            writer: new PngWriter(),
            data: $this->pairingUrl($captureSession),
            size: 320,
            margin: 16,
        ))->build();

        return response($result->getString(), 200, ['Content-Type' => $result->getMimeType()]);
    }

    /**
     * End a capture session early from the desktop side — the user closed
     * the pairing dialog before the phone finished, or before it expired.
     *
     * @param Document $document The document the session must belong to.
     * @param DocumentCaptureSession $captureSession The session to cancel.
     *
     * @return RedirectResponse Redirect back to the previous page.
     *
     * @throws AuthorizationException If the current user cannot cancel $captureSession.
     */
    public function cancel(Document $document, DocumentCaptureSession $captureSession): RedirectResponse
    {
        abort_if($captureSession->document_id !== $document->id, 404);

        $this->authorize('cancel', $captureSession);

        $captureSession->cancel();

        return back();
    }

    /**
     * The signed, expiring link a phone follows to reach this session's
     * capture page — the same URL for the initial page load and every
     * subsequent upload, since the `signed` middleware validates the request
     * URL as a whole rather than the HTTP method.
     *
     * @param DocumentCaptureSession $captureSession The session to link to.
     *
     * @return string The absolute, signed capture URL.
     */
    private function pairingUrl(DocumentCaptureSession $captureSession): string
    {
        return SignedLink::temporary(
            'capture.show',
            $captureSession->expires_at,
            ['captureSession' => $captureSession->id],
        );
    }
}
