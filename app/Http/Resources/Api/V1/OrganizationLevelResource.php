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
            // How many children a node here may hold before the next one is
            // allocated. Null means no ceiling.
            'capacity' => $this->capacity,
            'has_printable_label' => $this->has_printable_label,
            // Whether a node's value is allocated in sequence or typed by
            // hand, which decides whether a client may create one without
            // naming it.
            'value_strategy' => $this->value_strategy->value,
            // The bottom tier: where documents actually come to rest.
            'is_leaf' => $this->isLeaf(),
        ];
    }
}
