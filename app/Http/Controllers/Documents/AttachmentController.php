<?php

namespace App\Http\Controllers\Documents;

use App\Actions\Documents\DeleteAttachment;
use App\Actions\Documents\UploadAttachment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\StoreAttachmentRequest;
use App\Models\Document;
use App\Models\DocumentAttachment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    public function store(StoreAttachmentRequest $request, Document $document, UploadAttachment $action): RedirectResponse
    {
        $this->authorize('create', [DocumentAttachment::class, $document]);

        $action->handle($document, $request->file('file'), $request->user());

        return back();
    }

    public function show(DocumentAttachment $attachment): StreamedResponse
    {
        $this->authorize('view', $attachment);

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->filename);
    }

    public function destroy(DocumentAttachment $attachment, DeleteAttachment $action): RedirectResponse
    {
        $this->authorize('delete', $attachment);

        $action->handle($attachment);

        return back();
    }
}
