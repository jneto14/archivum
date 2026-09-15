<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserResource;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * Identify the user the calling token belongs to.
     *
     * What a client asks first, to find out whose archive it is holding a key
     * to and which workspaces that reaches.
     *
     * @param Request $request The incoming request, authenticated by a personal access token.
     *
     * @return UserResource The token's owner.
     */
    public function show(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
