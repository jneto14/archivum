<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\WorkspaceUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Somebody's membership of a workspace.
 *
 * Keyed by the user's id rather than the membership row's, because that is
 * what the routes address a member by and what a client already holds from a
 * document's `creator` or an attachment's `uploader`.
 *
 * @mixin WorkspaceUser
 */
class WorkspaceMemberResource extends JsonResource
{
    /**
     * @param Request $request The incoming request.
     *
     * @return array<string, mixed> The member's identity and role.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->user->id,
            'name' => $this->user->name,
            'email' => $this->user->email,
            'role' => $this->role->value,
            'joined_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
