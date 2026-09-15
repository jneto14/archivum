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
     * @return array{id: string, key: string, name: string} The type's public attributes.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // What the organization rules match on, so a client filing
            // documents automatically needs it as much as the display name.
            'key' => $this->key,
            'name' => $this->name,
        ];
    }
}
