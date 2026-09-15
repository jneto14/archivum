<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\DocumentType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DocumentType
 */
class DocumentTypeResource extends JsonResource
{
    /**
     * @param Request $request The incoming request.
     *
     * @return array<string, mixed> The type's public attributes, plus the document count when it was loaded.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // What the organization rules match on, so a client filing
            // documents automatically needs it as much as the display name.
            'key' => $this->key,
            'name' => $this->name,
            // Only on the listing, which counts them. A client deciding
            // whether a type is safe to delete wants this; a document's own
            // `document_type` has no use for it.
            'documents_count' => $this->whenCounted('documents'),
        ];
    }
}
