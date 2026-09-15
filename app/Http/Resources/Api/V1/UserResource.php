<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * Transform the user into the API's representation of them.
     *
     * Wrapping is left at Laravel's default `data`, unlike the Inertia
     * resources, which disable it because a React prop wants the attributes
     * directly. Here the envelope is the point: it leaves room to add meta
     * alongside a payload without the payload's own shape changing.
     *
     * @param Request $request The incoming request.
     *
     * @return array<string, mixed> The user's profile.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'timezone' => $this->timezone,
            'locale' => $this->locale,
            'is_platform_admin' => (bool) $this->is_platform_admin,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
