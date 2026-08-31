<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Actions\Workspace\CalculateWorkspaceUsage;
use App\Jobs\ExtractAttachmentText;
use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class UploadAttachment
{
    public function __construct(private readonly CalculateWorkspaceUsage $calculateUsage) {}

    /**
     * Store an uploaded file and attach it to a Document.
     *
     * @param Document $document The document the file is attached to; determines the owning workspace and storage path.
     * @param UploadedFile $file The uploaded file to store.
     * @param User $uploader The user recorded as having uploaded the file.
     *
     * @return DocumentAttachment The newly created attachment record.
     *
     * @throws ValidationException If the workspace has reached its attachment count limit, or storing $file would exceed its storage limit.
     */
    public function handle(Document $document, UploadedFile $file, User $uploader): DocumentAttachment
    {
        $workspace = $document->workspace;
        $limits = $workspace->limits;

        if ($limits?->exceedsAttachments($this->calculateUsage->attachments($workspace))) {
            throw ValidationException::withMessages([
                'file' => __('document.attachment_limit_reached'),
            ]);
        }

        if ($limits?->exceedsStorage($this->calculateUsage->storageBytes($workspace), $file->getSize())) {
            throw ValidationException::withMessages([
                'file' => __('document.storage_limit_exceeded'),
            ]);
        }

        $disk = config('archivum.attachments.disk');
        $path = $file->store("documents/{$document->id}", $disk);

        $attachment = DocumentAttachment::query()->create([
            'document_id' => $document->id,
            'uploaded_by' => $uploader->id,
            'disk' => $disk,
            'path' => $path,
            'filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'checksum' => hash_file('sha256', $file->getRealPath()),
        ]);

        $this->calculateUsage->forget($workspace);

        // Queued rather than inline: OCR on a multi-page scan takes seconds per
        // page, and the upload response must not wait for it. With extraction
        // switched off the attachment is settled straight away as unavailable,
        // so the document page says so instead of showing a "pending" that will
        // never resolve.
        if (config('archivum.ocr.enabled')) {
            ExtractAttachmentText::dispatch($attachment);
        } else {
            $attachment->markOcrUnavailable();
        }

        return $attachment;
    }
}
