<?php

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
     * @return array<string, mixed>
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
        ];
    }
}
