<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Document
 */
class DocumentResource extends JsonResource
{
    /**
     * Disable the default `{"data": {...}}` wrapping — this resource is used both as a
     * single Inertia prop (`documents/show`, `documents/form`) where the frontend
     * expects the document's attributes directly, and inside a paginated collection
     * (`documents.search`, `documents/index`) where the collection itself already
     * wraps under `data`.
     *
     * @var string|null
     */
    public static $wrap = null;

    /**
     * Transform the document into its public array representation.
     *
     * @param Request $request The incoming request.
     *
     * @return array<string, mixed> The document's public attributes, plus any eager-loaded relations.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'document_date' => $this->document_date?->toDateString(),
            'metadata' => $this->metadata,
            'document_type' => $this->whenLoaded('documentType', fn () => [
                'id' => $this->documentType->id,
                'name' => $this->documentType->name,
                'key' => $this->documentType->key,
            ]),
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->map(fn ($tag) => [
                'id' => $tag->id,
                'name' => $tag->name,
            ])->values()),
            'current_location' => $this->whenLoaded(
                'currentLocation',
                fn () => $this->currentLocation?->node?->path(),
            ),
            'creator' => $this->whenLoaded('creator', fn () => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ]),
            'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn ($attachment) => [
                'id' => $attachment->id,
                'filename' => $attachment->filename,
                'mime_type' => $attachment->mime_type,
                'is_previewable' => $attachment->is_previewable,
                'size' => $attachment->size,
                'ocr_status' => $attachment->ocr_status->value,
                'created_at' => $attachment->created_at?->toIso8601String(),
                'uploader' => $attachment->relationLoaded('uploader') ? [
                    'id' => $attachment->uploader->id,
                    'name' => $attachment->uploader->name,
                ] : null,
                'duplicate_of' => $attachment->relationLoaded('duplicateOf') && $attachment->duplicateOf !== null ? [
                    'document_id' => $attachment->duplicateOf->document_id,
                    'document_title' => $attachment->duplicateOf->document?->title,
                    'filename' => $attachment->duplicateOf->filename,
                ] : null,
                'file_uploaded_at' => $attachment->fileUploadedAt()?->toIso8601String(),
                'versions' => $attachment->relationLoaded('versions')
                    ? $attachment->versions->map(fn ($version) => [
                        'id' => $version->id,
                        'filename' => $version->filename,
                        'size' => $version->size,
                        'uploaded_at' => $version->uploaded_at->toIso8601String(),
                        'superseded_at' => $version->superseded_at->toIso8601String(),
                        'uploader' => $version->relationLoaded('uploader') && $version->uploader !== null ? [
                            'id' => $version->uploader->id,
                            'name' => $version->uploader->name,
                        ] : null,
                    ])->values()->all()
                    : [],
            ])->values()->all()),
            'location_history' => $this->whenLoaded('locations', fn () => $this->locations->map(fn ($location) => [
                'id' => $location->id,
                'path' => $location->node?->path(),
                'created_at' => $location->created_at?->toIso8601String(),
            ])->values()->all()),
        ];
    }
}
