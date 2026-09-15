<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @mixin Tag
 */
class TagResource extends JsonResource
{
    /**
     * @param Request $request The incoming request.
     *
     * @return array<string, mixed> The tag's public attributes, plus the usage figures when the listing selected them.
     */
    public function toArray(Request $request): array
    {
        $lastUsed = $this->getAttribute('last_used_at');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'documents_count' => $this->whenCounted('documents'),
            // Selected by the listing rather than stored on a tag, so it is
            // read off the query result and absent everywhere else.
            'last_used_at' => $this->when(
                $lastUsed !== null,
                fn (): string => Carbon::parse($lastUsed)->toIso8601String(),
            ),
        ];
    }
}
