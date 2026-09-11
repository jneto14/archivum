<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use App\Actions\Documents\ReextractAttachmentText;
use App\Actions\Documents\TrashAttachment;
use App\Actions\Documents\UploadAttachment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\StoreAttachmentRequest;
use App\Models\Document;
use App\Models\DocumentAttachment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
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

        $inlineSafe = $attachment->is_previewable;

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
     * Move an attachment to the workspace's trash, leaving its stored file
     * on disk until the trash is emptied or pruned.
     *
     * @param DocumentAttachment $attachment The attachment to trash.
     * @param TrashAttachment $action Trashes the attachment and re-indexes its document.
     *
     * @return RedirectResponse Redirect back to the previous page.
     *
     * @throws AuthorizationException If the current user cannot delete $attachment.
     */
    public function destroy(DocumentAttachment $attachment, TrashAttachment $action): RedirectResponse
    {
        $this->authorize('delete', $attachment);

        $action->handle($attachment);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('document.attachment_trashed')]);

        return back();
    }

    /**
     * Read this attachment's text again.
     *
     * The reading a file got depends on the pipeline that was current when it
     * was uploaded, and on the settings in force at the time. A file read
     * badly, or read before the confidence filter existed, or read while a
     * language pack was missing, has no other way to be brought up to date —
     * and the failures the Tasks page can retry are only retryable while their
     * task row is still on the page (ARC-122).
     *
     * Allowed to anyone who may edit the attachment rather than to admins
     * only: this asks a question about one file that whoever filed it is best
     * placed to ask, and the answer replaces a reading they can already throw
     * away outright with `rejectOcr`.
     *
     * @param DocumentAttachment $attachment The attachment to read again.
     * @param Request $request The incoming request; used to resolve the current user.
     * @param ReextractAttachmentText $action Queues the extraction and its task.
     *
     * @return RedirectResponse Redirect back to the previous page.
     *
     * @throws AuthorizationException If the current user cannot update $attachment.
     * @throws ValidationException If extraction is switched off, or the attachment is already being read.
     */
    public function reextract(DocumentAttachment $attachment, Request $request, ReextractAttachmentText $action): RedirectResponse
    {
        $this->authorize('update', $attachment);

        $action->handle($attachment, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('document.reextraction_queued')]);

        return back();
    }

    /**
     * Dismiss an attachment's duplicate warning, keeping both copies.
     *
     * @param DocumentAttachment $attachment The attachment whose warning is dismissed.
     *
     * @return RedirectResponse Redirect back to the previous page.
     *
     * @throws AuthorizationException If the current user cannot update $attachment.
     */
    public function dismissDuplicate(DocumentAttachment $attachment): RedirectResponse
    {
        $this->authorize('update', $attachment);

        $attachment->dismissDuplicate();

        return back();
    }

    /**
     * Keep what OCR made of an attachment, because somebody has read it and it
     * is right.
     *
     * Also the answer for a page the engine refused itself, where there is no
     * text to keep and this only stops it being counted.
     *
     * @param DocumentAttachment $attachment The attachment whose reading is accepted.
     *
     * @return RedirectResponse Redirect back to the previous page.
     *
     * @throws AuthorizationException If the current user cannot update $attachment.
     */
    public function confirmOcr(DocumentAttachment $attachment): RedirectResponse
    {
        $this->authorize('update', $attachment);

        $attachment->confirmOcr();

        return back();
    }

    /**
     * Throw away what OCR made of an attachment, because somebody has read it
     * and it is wrong.
     *
     * The text is what feeds the search index and the duplicate fingerprint,
     * so a reading nobody believes has to stop being one — flagging it would
     * leave it doing its damage. The document's mirror is rebuilt from what is
     * left, which is why this cannot live on the model alone (ARC-118).
     *
     * @param DocumentAttachment $attachment The attachment whose reading is refused.
     *
     * @return RedirectResponse Redirect back to the previous page.
     *
     * @throws AuthorizationException If the current user cannot update $attachment.
     */
    public function rejectOcr(DocumentAttachment $attachment): RedirectResponse
    {
        $this->authorize('update', $attachment);

        $attachment->rejectOcr();
        $attachment->document?->refreshOcrText();

        return back();
    }
}
