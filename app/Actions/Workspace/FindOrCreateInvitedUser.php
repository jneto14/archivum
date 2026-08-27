<?php

namespace App\Actions\Workspace;

use App\Models\User;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class FindOrCreateInvitedUser
{
    /**
     * Find a User by email, or create one and send them a password-reset
     * link to set their own password. Archivum has no public registration
     * (accounts only exist via the seeded admin or being invited), so
     * inviting a new email is the only way to bring someone in.
     */
    public function handle(string $email, ?string $name): User
    {
        $user = User::query()->where('email', $email)->first();

        if ($user !== null) {
            return $user;
        }

        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Str::random(40),
        ]);

        Password::sendResetLink(['email' => $email]);

        return $user;
    }
}
