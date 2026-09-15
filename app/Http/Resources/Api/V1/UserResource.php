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
     * @return array{id: string, name: string, email: string, created_at: string|null} The user's public attributes.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
