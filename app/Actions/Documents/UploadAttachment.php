<?php

namespace App\Actions\Documents;

use App\Actions\Workspace\CalculateWorkspaceUsage;
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

        return DocumentAttachment::query()->create([
            'document_id' => $document->id,
            'uploaded_by' => $uploader->id,
            'disk' => $disk,
            'path' => $path,
            'filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'checksum' => hash_file('sha256', $file->getRealPath()),
        ]);
    }
}
