<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\OrganizationScheme;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * How a workspace's physical archive is laid out.
 *
 * @mixin OrganizationScheme
 */
class OrganizationSchemeResource extends JsonResource
{
    /**
     * @param Request $request The incoming request.
     *
     * @return array<string, mixed> The scheme's public attributes, plus its levels and rules when loaded.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'name' => $this->name,
            'created_at' => $this->created_at?->toIso8601String(),
            'levels' => OrganizationLevelResource::collection($this->whenLoaded('levels')),
            'rules' => OrganizationRuleResource::collection($this->whenLoaded('rules')),
        ];
    }
}
