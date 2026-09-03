<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use App\Actions\Documents\UploadAttachment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\StoreCapturePhotoRequest;
use App\Models\DocumentCaptureSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The phone side of mobile capture. Neither action here calls `authorize()`
 * — there is no authenticated user to check. The `signed` route middleware
 * (routes/capture.php) is the entire access control: reaching either method
 * at all already proves the request carries the exact link the desktop
 * showed as a QR code, unmodified and not yet expired. What's checked here
 * is `DocumentCaptureSession::isActive()`, which can say no for reasons the
 * signature alone can't — the desktop cancelled the session, or the phone
 * already tapped "done" — while the signed link's own time window is still
 * open.
 */
class CapturePageController extends Controller
{
    /**
     * Show the capture page: the camera picker if the session is still open,
     * or an explanation of why it isn't.
     *
     * @param DocumentCaptureSession $captureSession The session this link belongs to.
     *
     * @return Response The rendered capture page.
     */
    public function show(DocumentCaptureSession $captureSession): Response
    {
        $captureSession->loadMissing('document');

        return Inertia::render('capture/show', [
            'document_title' => $captureSession->document->title,
            'active' => $captureSession->isActive(),
            // Distinct from `active`: this is why not, when it isn't — the
            // session was cancelled or completed deliberately versus simply
            // having run out its clock. `active` alone can't tell those
            // apart, since `isActive()` folds expiry into the same false.
            'status' => $captureSession->status->value,
            'photos_count' => $captureSession->photos_count,
        ]);
    }

    /**
     * Either store one or more captured photos as attachments, or — when the
     * phone taps "done" — end the session.
     *
     * @param DocumentCaptureSession $captureSession The session this link belongs to.
     * @param StoreCapturePhotoRequest $request The incoming request, carrying either `files` or `done`.
     * @param UploadAttachment $action Stores the files and creates the DocumentAttachment records.
     *
     * @return RedirectResponse Redirect back to the capture page.
     *
     * @throws ValidationException If the workspace's attachment count or storage limit would be exceeded by the batch.
     */
    public function store(
        DocumentCaptureSession $captureSession,
        StoreCapturePhotoRequest $request,
        UploadAttachment $action,
    ): RedirectResponse {
        if (!$captureSession->isActive()) {
            return back();
        }

        if ($request->isDone()) {
            $captureSession->complete();

            return back();
        }

        $action->handleMany($captureSession->document, $request->attachments(), $captureSession->creator);
        $captureSession->recordPhoto();

        return back();
    }
}
