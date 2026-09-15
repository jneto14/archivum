<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Documents\ReextractAttachmentText;
use App\Actions\Documents\ReplaceAttachmentFile;
use App\Actions\Documents\TrashAttachment;
use App\Actions\Documents\UploadAttachment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\ReplaceAttachmentRequest;
use App\Http\Requests\Documents\StoreAttachmentRequest;
use App\Http\Resources\Api\V1\AttachmentResource;
use App\Models\Document;
use App\Models\DocumentAttachment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    /**
     * List a document's attachments.
     *
     * Unpaginated: these are the scans of one document, and a client asking
     * for them wants the set.
     *
     * @param Document $document The document whose attachments are listed.
     *
     * @return AnonymousResourceCollection The document's attachments, oldest first.
     *
     * @throws AuthorizationException If the token's user cannot view $document.
     */
    public function index(Document $document): AnonymousResourceCollection
    {
        $this->authorize('view', $document);

        return AttachmentResource::collection(
            $document->attachments()
                ->with(['uploader', 'duplicateOf', 'versions.uploader'])
                ->oldest()
                ->get(),
        );
    }

    /**
     * Upload one or more files onto a document.
     *
     * Multipart, under `files[]`, and always a list even for a single file —
     * the same shape and the same request the interface posts. A file that
     * fails validation, or a batch that would cross a workspace limit, fails
     * the whole request: an upload never leaves the document half changed.
     *
     * @param StoreAttachmentRequest $request The incoming request carrying the uploaded files.
     * @param Document $document The document the files are attached to.
     * @param UploadAttachment $action Stores each file and queues a reading.
     *
     * @return JsonResponse The created attachments, with status 201.
     *
     * @throws AuthorizationException If the token's user cannot add attachments to $document.
     * @throws ValidationException If the workspace's storage or attachment limit would be exceeded.
     */
    public function store(StoreAttachmentRequest $request, Document $document, UploadAttachment $action): JsonResponse
    {
        $this->authorize('create', [DocumentAttachment::class, $document]);

        $created = $action->handleMany($document, $request->attachments(), $request->user());

        $attachments = $document->attachments()
            ->whereKey(array_map(
                static fn (DocumentAttachment $attachment): string => $attachment->id,
                $created,
            ))
            ->with(['uploader', 'versions.uploader'])
            ->oldest()
            ->get();

        return AttachmentResource::collection($attachments)->response()->setStatusCode(201);
    }

    /**
     * Read one attachment's details, including what was extracted from it.
     *
     * The metadata, not the bytes — `/file` serves those. The web route of the
     * same shape is the download, because a browser following a link wants the
     * file; a client wants to know what it is about to fetch.
     *
     * @param DocumentAttachment $attachment The attachment being read.
     *
     * @return AttachmentResource The attachment, its versions and its reading.
     *
     * @throws AuthorizationException If the token's user cannot view $attachment.
     */
    public function show(DocumentAttachment $attachment): AttachmentResource
    {
        $this->authorize('view', $attachment);

        $attachment->load(['uploader', 'duplicateOf', 'versions.uploader']);

        return (new AttachmentResource($attachment))->withText();
    }

    /**
     * Download an attachment's file.
     *
     * @param DocumentAttachment $attachment The attachment whose stored file is served.
     *
     * @return StreamedResponse A streamed download of the underlying file.
     *
     * @throws AuthorizationException If the token's user cannot view $attachment.
     */
    public function download(DocumentAttachment $attachment): StreamedResponse
    {
        $this->authorize('view', $attachment);

        return Storage::disk($attachment->disk)->download(
            $attachment->path,
            $attachment->filename,
            ['X-Content-Type-Options' => 'nosniff'],
        );
    }

    /**
     * Stream an attachment's file inline, for a client that renders it.
     *
     * Carries the interface's inline-safety rules unchanged, because they are
     * about what a browser will do with the bytes and a client may well be a
     * browser: a type this application does not consider safe to render is
     * served as octet-stream with `nosniff`, whatever it claims to be.
     *
     * @param DocumentAttachment $attachment The attachment whose stored file is served inline.
     *
     * @return StreamedResponse An inline stream of the underlying file.
     *
     * @throws AuthorizationException If the token's user cannot view $attachment.
     */
    public function preview(DocumentAttachment $attachment): StreamedResponse
    {
        $this->authorize('view', $attachment);

        $inlineSafe = $attachment->is_previewable;

        return Storage::disk($attachment->disk)->response(
            $attachment->path,
            $attachment->filename,
            [
                'Content-Type' => $inlineSafe ? $attachment->mime_type : 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
            ],
            $inlineSafe ? 'inline' : 'attachment',
        );
    }

    /**
     * Put a different file in an attachment's place, keeping the one it
     * replaces.
     *
     * Open to anyone who may edit the attachment, rather than to the uploader
     * and admins as deleting one is: deleting loses the file, and this is the
     * operation that exists so nothing is lost (ARC-124).
     *
     * @param ReplaceAttachmentRequest $request The incoming request carrying the replacement file.
     * @param DocumentAttachment $attachment The attachment whose file is replaced.
     * @param ReplaceAttachmentFile $action Archives the current file, stores the new one and queues a fresh reading.
     *
     * @return AttachmentResource The attachment, now holding the new file, with the old one in its history.
     *
     * @throws AuthorizationException If the token's user cannot update $attachment.
     * @throws ValidationException If storing the file would exceed the workspace's storage limit.
     */
    public function replace(ReplaceAttachmentRequest $request, DocumentAttachment $attachment, ReplaceAttachmentFile $action): AttachmentResource
    {
        $this->authorize('update', $attachment);

        $action->handle($attachment, $request->replacement(), $request->user());

        return new AttachmentResource(
            $attachment->refresh()->load(['uploader', 'duplicateOf', 'versions.uploader']),
        );
    }

    /**
     * Move an attachment to the workspace's trash.
     *
     * The file stays on disk until the trash is emptied or pruned, which is
     * what makes this reversible.
     *
     * @param DocumentAttachment $attachment The attachment to trash.
     * @param TrashAttachment $action Trashes the attachment and re-indexes its document.
     *
     * @return JsonResponse An empty 204.
     *
     * @throws AuthorizationException If the token's user cannot delete $attachment.
     */
    public function destroy(DocumentAttachment $attachment, TrashAttachment $action): JsonResponse
    {
        $this->authorize('delete', $attachment);

        $action->handle($attachment);

        return new JsonResponse(status: 204);
    }

    /**
     * Dismiss an attachment's duplicate warning, keeping both copies.
     *
     * @param DocumentAttachment $attachment The attachment whose warning is dismissed.
     *
     * @return AttachmentResource The attachment, no longer flagged.
     *
     * @throws AuthorizationException If the token's user cannot update $attachment.
     */
    public function dismissDuplicate(DocumentAttachment $attachment): AttachmentResource
    {
        $this->authorize('update', $attachment);

        $attachment->dismissDuplicate();

        return new AttachmentResource($attachment->refresh());
    }

    /**
     * Read the file again, replacing whatever the last reading produced.
     *
     * @param Request $request The incoming request, used to resolve the acting user.
     * @param DocumentAttachment $attachment The attachment to read again.
     * @param ReextractAttachmentText $action Queues the extraction.
     *
     * @return AttachmentResource The attachment, with its status now reflecting the queued reading.
     *
     * @throws AuthorizationException If the token's user cannot update $attachment.
     * @throws ValidationException If extraction is switched off, or the attachment is already being read.
     */
    public function reextract(Request $request, DocumentAttachment $attachment, ReextractAttachmentText $action): AttachmentResource
    {
        $this->authorize('update', $attachment);

        $action->handle($attachment, $request->user());

        return new AttachmentResource($attachment->refresh());
    }

    /**
     * Keep what OCR made of an attachment, because somebody has read it and it
     * is right.
     *
     * @param DocumentAttachment $attachment The attachment whose reading is accepted.
     *
     * @return AttachmentResource The attachment, off the review queue.
     *
     * @throws AuthorizationException If the token's user cannot update $attachment.
     */
    public function confirmReading(DocumentAttachment $attachment): AttachmentResource
    {
        $this->authorize('update', $attachment);

        $attachment->confirmOcr();

        return new AttachmentResource($attachment->refresh());
    }

    /**
     * Throw away what OCR made of an attachment, because somebody has read it
     * and it is wrong.
     *
     * The text feeds the search index and the duplicate fingerprint, so a
     * reading nobody believes has to stop being one rather than be flagged and
     * left doing its damage. The document's mirror is rebuilt from what is
     * left (ARC-118).
     *
     * @param DocumentAttachment $attachment The attachment whose reading is refused.
     *
     * @return AttachmentResource The attachment, with its reading discarded.
     *
     * @throws AuthorizationException If the token's user cannot update $attachment.
     */
    public function rejectReading(DocumentAttachment $attachment): AttachmentResource
    {
        $this->authorize('update', $attachment);

        $attachment->rejectOcr();
        $attachment->document?->refreshOcrText();

        return new AttachmentResource($attachment->refresh());
    }
}
