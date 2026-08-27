<?php

namespace App\Actions\Documents;

use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;

class UploadAttachment
{
    /**
     * Store an uploaded file and attach it to a Document.
     */
    public function handle(Document $document, UploadedFile $file, User $uploader): DocumentAttachment
    {
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
