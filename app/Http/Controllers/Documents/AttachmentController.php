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
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    /**
     * Upload a file and attach it to the given document.
     *
     * @param StoreAttachmentRequest $request The incoming request, carrying the uploaded `file`.
     * @param Document $document The document the attachment is stored against.
     * @param UploadAttachment $action Stores the file and creates the DocumentAttachment record.
     *
     * @return RedirectResponse Redirect back to the previous page.
     *
     * @throws AuthorizationException If the current user cannot create attachments on $document.
     * @throws ValidationException If the workspace's attachment count or storage limit would be exceeded.
     */
    public function store(StoreAttachmentRequest $request, Document $document, UploadAttachment $action): RedirectResponse
    {
        $this->authorize('create', [DocumentAttachment::class, $document]);

        $action->handle($document, $request->file('file'), $request->user());

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

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->filename);
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

        return back();
    }
}
