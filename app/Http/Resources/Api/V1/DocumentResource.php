<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A document as the API promises it.
 *
 * Deliberately not App\Http\Resources\DocumentResource. That one serializes
 * props for React: it disables `data` wrapping because a page wants the
 * attributes directly, and carries `is_previewable`, `duplicate_of` and an
 * inlined version history — all of which exist because some component needed
 * them, and any of which may stop existing when that component changes. A
 * response shape is a commitment in a way a page prop is not (ARC-121).
 *
 * Relations appear only when they were loaded, so a listing and a single
 * document can share this without the listing paying for what it did not ask
 * for.
 *
 * @mixin Document
 */
class DocumentResource extends JsonResource
{
    /**
     * @param Request $request The incoming request.
     *
     * @return array<string, mixed> The document's public attributes, plus any eager-loaded relations.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'title' => $this->title,
            'document_date' => $this->document_date?->toDateString(),
            'metadata' => $this->metadata ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'document_type' => new DocumentTypeResource($this->whenLoaded('documentType')),
            'tags' => TagResource::collection($this->whenLoaded('tags')),
            'creator' => $this->whenLoaded('creator', fn (): array => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ]),
            // Where the physical document is now, not where it has been. The
            // node's id travels with the path because the path is assembled
            // for reading and the id is what a client moves it by.
            'current_location' => $this->whenLoaded(
                'currentLocation',
                fn (): ?array => $this->currentLocation?->node === null ? null : [
                    'node_id' => $this->currentLocation->node->id,
                    'path' => $this->currentLocation->node->path(),
                ],
            ),
        ];
    }
}
