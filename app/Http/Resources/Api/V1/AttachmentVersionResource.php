<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\DocumentAttachmentVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A file an attachment used to hold.
 *
 * Not numbered, because the chain is not a stack of revisions: it is the set
 * of files the attachment is *not* currently holding, ordered by when each
 * stopped being current. `uploaded_at` and `superseded_at` bracket the stretch
 * during which it was the live file, which is what identifies it (ARC-124).
 *
 * @mixin DocumentAttachmentVersion
 */
class AttachmentVersionResource extends JsonResource
{
    /**
     * @param Request $request The incoming request.
     *
     * @return array<string, mixed> The version's public attributes.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'attachment_id' => $this->document_attachment_id,
            'filename' => $this->filename,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'uploaded_at' => $this->uploaded_at->toIso8601String(),
            'superseded_at' => $this->superseded_at->toIso8601String(),
            'uploader' => $this->whenLoaded('uploader', fn (): ?array => $this->uploader === null ? null : [
                'id' => $this->uploader->id,
                'name' => $this->uploader->name,
            ]),
        ];
    }
}
