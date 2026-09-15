<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\IntakeLabel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A phrase this archive was seen writing in front of a value, and adopted.
 *
 * @mixin IntakeLabel
 */
class IntakeLabelResource extends JsonResource
{
    /**
     * @param Request $request The incoming request.
     *
     * @return array<string, mixed> The label's public attributes.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            // What the field is called here: a shipped name, or this
            // workspace's own spelling of it.
            'field' => $this->field,
            'label' => $this->label,
            'status' => $this->status->value,
            // How many times the archive saw it before proposing it.
            'support' => $this->support,
        ];
    }
}
