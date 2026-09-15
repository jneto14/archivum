<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\OrganizationLevel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One tier of a scheme — a cover, a letter, a position on a shelf.
 *
 * @mixin OrganizationLevel
 */
class OrganizationLevelResource extends JsonResource
{
    /**
     * @param Request $request The incoming request.
     *
     * @return array<string, mixed> The level's public attributes.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'scheme_id' => $this->scheme_id,
            'name' => $this->name,
            'key' => $this->key,
            'position' => $this->position,
            'capacity' => $this->capacity,
            'has_printable_label' => $this->has_printable_label,
            'value_strategy' => $this->value_strategy->value,
            'is_leaf' => $this->isLeaf(),
        ];
    }
}
