<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\OrganizationNode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A place in the archive: this cover, that letter, this position.
 *
 * @mixin OrganizationNode
 */
class OrganizationNodeResource extends JsonResource
{
    /**
     * @param Request $request The incoming request.
     *
     * @return array<string, mixed> The node's public attributes.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'level_id' => $this->level_id,
            'parent_id' => $this->parent_id,
            'value' => $this->value,
            'path' => $this->path(),
            'level' => new OrganizationLevelResource($this->whenLoaded('level')),
            'children_count' => $this->whenCounted('children'),
        ];
    }
}
