<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Http\Resources\Api\V1\UserResource;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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

    /**
     * Update the profile of the user this token belongs to.
     *
     * A token can change its own user's name, email, timezone and language —
     * the account data the interface keeps on Settings → Profile. It cannot
     * change anybody else's: there is no user this is addressed by, because
     * there is no user but the token's own.
     *
     * The same Form Request the browser posts to, so the uniqueness check, the
     * locale whitelist and the demo installation's refusal to let its login
     * address be changed all hold here without being restated.
     *
     * Changing the email clears its verification, exactly as it does in the
     * browser: the new address has not been shown to belong to anybody yet.
     *
     * Deleting the account and changing the password stay off the API. Both
     * turn on proving the current password, which is a thing a token holder
     * may well not have — a token is a key to the archive, not to the account
     * behind it.
     *
     * @param ProfileUpdateRequest $request The incoming request with the validated profile.
     *
     * @return UserResource The updated profile.
     *
     * @throws ValidationException If the email is taken, or is being changed on a demo installation.
     */
    public function update(ProfileUpdateRequest $request): UserResource
    {
        $user = $request->user();

        $user->fill($request->validated());

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        return new UserResource($user);
    }
}
