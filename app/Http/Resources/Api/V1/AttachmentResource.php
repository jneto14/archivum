<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\DocumentAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An attachment as the API promises it.
 *
 * @mixin DocumentAttachment
 */
class AttachmentResource extends JsonResource
{
    /**
     * Whether this response carries the text extracted from the file.
     *
     * Off by default. A listing of a document's scans would otherwise hand
     * back every page of every reading, and a document with fifty scans turns
     * a listing into a transfer. The single-attachment endpoint opts in,
     * because there the text is what was asked for.
     */
    private bool $withText = false;

    /**
     * Include the extracted text in this response.
     *
     * @return self The same resource, carrying its reading.
     */
    public function withText(): self
    {
        $this->withText = true;

        return $this;
    }

    /**
     * @param Request $request The incoming request.
     *
     * @return array<string, mixed> The attachment's public attributes, plus any eager-loaded relations.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_id' => $this->document_id,
            'filename' => $this->filename,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'checksum' => $this->checksum,
            // Whether this application will serve the file inline. An SVG is
            // `image/*` and is still served as an opaque download, so a client
            // cannot work this out from the mime type alone.
            'is_previewable' => $this->is_previewable,
            'ocr_status' => $this->ocr_status->value,
            'ocr_text' => $this->when($this->withText, fn (): ?string => $this->resource->ocr_text),
            'created_at' => $this->created_at?->toIso8601String(),
            // Only ever present on something in the trash, which is exactly
            // where it means anything.
            'deleted_at' => $this->when(
                $this->deleted_at !== null,
                fn (): ?string => $this->deleted_at?->toIso8601String(),
            ),
            // When the file sitting here now arrived, which stops agreeing
            // with `created_at` the first time something replaces it.
            'file_uploaded_at' => $this->fileUploadedAt()?->toIso8601String(),
            'uploader' => $this->whenLoaded('uploader', fn (): ?array => $this->uploader === null ? null : [
                'id' => $this->uploader->id,
                'name' => $this->uploader->name,
            ]),
            // The earlier copy this file appears to be of, until somebody
            // dismisses the warning.
            'duplicate_of' => $this->whenLoaded('duplicateOf', fn (): ?array => $this->duplicateOf === null ? null : [
                'attachment_id' => $this->duplicateOf->id,
                'document_id' => $this->duplicateOf->document_id,
                'filename' => $this->duplicateOf->filename,
            ]),
            'versions' => AttachmentVersionResource::collection($this->whenLoaded('versions')),
        ];
    }
}
