<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Documents\CreateCaptureSession;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CaptureSessionResource;
use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\DocumentCaptureSession;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The half of mobile capture that belongs to whoever is holding the archive.
 *
 * The other half — the page the phone loads and uploads through — stays
 * outside the API and outside authentication, because a phone scanning a code
 * has no session and never will. Its signed URL is what this hands back.
 */
class CaptureSessionController extends Controller
{
    /**
     * Start a pairing session for a document.
     *
     * Naming an attachment aims the session at replacing that file rather than
     * adding a page. Such a session takes one photo and ends.
     *
     * @param Request $request The incoming request, optionally carrying the `attachment` to replace.
     * @param Document $document The document the session is for.
     * @param CreateCaptureSession $action Creates the session and cancels any other live one for the document.
     *
     * @return JsonResponse The session, with status 201.
     *
     * @throws AuthorizationException If the token's user cannot start a capture session for $document.
     * @throws ValidationException If `attachment` is given but is not one of $document's.
     */
    public function store(Request $request, Document $document, CreateCaptureSession $action): JsonResponse
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

        $session = $action->handle($document, $request->user(), $replaces);

        return (new CaptureSessionResource($session->refresh()))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Read a session's state.
     *
     * How a client waits for the phone: the interface polls its own page prop
     * for the same answer.
     *
     * @param Document $document The document the session must belong to.
     * @param DocumentCaptureSession $captureSession The session being read.
     *
     * @return CaptureSessionResource The session.
     *
     * @throws AuthorizationException If the token's user cannot view $captureSession.
     * @throws NotFoundHttpException If $captureSession does not belong to $document.
     */
    public function show(Document $document, DocumentCaptureSession $captureSession): CaptureSessionResource
    {
        abort_if($captureSession->document_id !== $document->id, 404);

        $this->authorize('view', $captureSession);

        return new CaptureSessionResource($captureSession);
    }

    /**
     * End a session early, before the phone has finished with it.
     *
     * @param Document $document The document the session must belong to.
     * @param DocumentCaptureSession $captureSession The session to cancel.
     *
     * @return CaptureSessionResource The cancelled session.
     *
     * @throws AuthorizationException If the token's user cannot cancel $captureSession.
     * @throws NotFoundHttpException If $captureSession does not belong to $document.
     */
    public function cancel(Document $document, DocumentCaptureSession $captureSession): CaptureSessionResource
    {
        abort_if($captureSession->document_id !== $document->id, 404);

        $this->authorize('cancel', $captureSession);

        $captureSession->cancel();

        return new CaptureSessionResource($captureSession->refresh());
    }
}
