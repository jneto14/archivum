<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\OrganizationRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * How a document's attributes decide where it is filed.
 *
 * @mixin OrganizationRule
 */
class OrganizationRuleResource extends JsonResource
{
    /**
     * @param Request $request The incoming request.
     *
     * @return array<string, mixed> The rule's public attributes.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'scheme_id' => $this->scheme_id,
            'matcher_key' => $this->matcher_key,
            'matcher_value' => $this->matcher_value,
            'target_level_id' => $this->target_level_id,
            'target_level' => new OrganizationLevelResource($this->whenLoaded('targetLevel')),
            'preferred_value' => $this->preferred_value,
        ];
    }
}
