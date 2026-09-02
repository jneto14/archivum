<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use App\Actions\Documents\DeleteAttachment;
use App\Actions\Documents\UploadAttachment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\StoreAttachmentRequest;
use App\Models\Document;
use App\Models\DocumentAttachment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    /**
     * The only content types ever served inline, and the exact type each is
     * served as.
     *
     * An attachment is a file somebody uploaded, served from the application's
     * own origin. Letting the browser decide what it is means an uploaded
     * `invoice.html` comes back as `text/html` and its script runs with the
     * viewer's session — able to read the CSRF token and act as them. So the
     * type is chosen here from a list, never taken from the file.
     *
     * `image/svg+xml` is deliberately absent. An SVG is a document that can
     * carry script, not a picture, and it is the one image type that would
     * turn this list back into the hole it closes.
     *
     * Anything not listed is still downloadable — an archive should accept
     * whatever its owner has — but it is handed over as an opaque download
     * rather than rendered.
     *
     * @var list<string>
     */
    private const INLINE_SAFE_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/avif',
    ];

    /**
     * Upload one or more files and attach them to the given document.
     *
     * @param StoreAttachmentRequest $request The incoming request, carrying the uploaded `files`.
     * @param Document $document The document the attachments are stored against.
     * @param UploadAttachment $action Stores the files and creates the DocumentAttachment records.
     *
     * @return RedirectResponse Redirect back to the previous page.
     *
     * @throws AuthorizationException If the current user cannot create attachments on $document.
     * @throws ValidationException If the workspace's attachment count or storage limit would be exceeded by the batch.
     */
    public function store(StoreAttachmentRequest $request, Document $document, UploadAttachment $action): RedirectResponse
    {
        $this->authorize('create', [DocumentAttachment::class, $document]);

        $attachments = $action->handleMany($document, $request->attachments(), $request->user());

        $count = count($attachments);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $count === 1
                ? __('document.attachment_uploaded')
                : __('document.attachments_uploaded', ['count' => $count]),
        ]);

        return back();
    }

    /**
     * Stream the attachment's file as a download.
     *
     * @param DocumentAttachment $attachment The attachment whose stored file should be downloaded.
     *
     * @return StreamedResponse A streamed download of the attachment's underlying file.
     *
     * @throws AuthorizationException If the current user cannot view $attachment.
     */
    public function show(DocumentAttachment $attachment): StreamedResponse
    {
        $this->authorize('view', $attachment);

        return Storage::disk($attachment->disk)->download(
            $attachment->path,
            $attachment->filename,
            ['X-Content-Type-Options' => 'nosniff'],
        );
    }

    /**
     * Stream the attachment's file inline, for previewing in the browser.
     *
     * @param DocumentAttachment $attachment The attachment whose stored file should be served inline.
     *
     * @return StreamedResponse An inline (non-download) stream of the attachment's underlying file.
     *
     * @throws AuthorizationException If the current user cannot view $attachment.
     */
    public function preview(DocumentAttachment $attachment): StreamedResponse
    {
        $this->authorize('view', $attachment);

        $inlineSafe = in_array($attachment->mime_type, self::INLINE_SAFE_TYPES, true);

        return Storage::disk($attachment->disk)->response(
            $attachment->path,
            $attachment->filename,
            [
                // Stated rather than detected. Without this the disk reports
                // the stored file's own type, which is how an uploaded .html
                // came back as text/html.
                'Content-Type' => $inlineSafe ? $attachment->mime_type : 'application/octet-stream',
                // Belt to the Content-Type's braces: stops a browser deciding
                // for itself that octet-stream bytes look like a document.
                'X-Content-Type-Options' => 'nosniff',
            ],
            $inlineSafe ? 'inline' : 'attachment',
        );
    }

    /**
     * Delete an attachment and its underlying stored file.
     *
     * @param DocumentAttachment $attachment The attachment to delete.
     * @param DeleteAttachment $action Deletes the stored file and the attachment record.
     *
     * @return RedirectResponse Redirect back to the previous page.
     *
     * @throws AuthorizationException If the current user cannot delete $attachment.
     */
    public function destroy(DocumentAttachment $attachment, DeleteAttachment $action): RedirectResponse
    {
        $this->authorize('delete', $attachment);

        $action->handle($attachment);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('document.attachment_deleted')]);

        return back();
    }
}
