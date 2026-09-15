<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Activitylog\Models\Activity;

/**
 * One entry in a workspace's audit trail.
 *
 * @mixin Activity
 */
class ActivityResource extends JsonResource
{
    /**
     * @param Request $request The incoming request.
     *
     * @return array<string, mixed> The entry's public attributes.
     */
    public function toArray(Request $request): array
    {
        $causer = $this->causer;
        $label = $this->getProperty('label');

        return [
            'id' => $this->id,
            'log_name' => $this->log_name,
            'event' => $this->event,
            'label' => is_string($label) ? $label : null,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'causer' => $causer instanceof User ? ['id' => $causer->id, 'name' => $causer->name] : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
