<?php

namespace App\Actions\Workspace;

use App\Models\User;
use App\Models\Workspace;
use App\Notifications\WorkspaceInvitation;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class FindOrCreateInvitedUser
{
    /**
     * Find a User by email, or create one and send them an invitation to
     * set their own password. Archivum has no public registration (accounts
     * only exist via the seeded admin or being invited), so inviting a new
     * email is the only way to bring someone in.
     *
     * @param  string  $email  The email address to look up or invite.
     * @param  string|null  $name  The name to use if a new user is created; ignored if a user with $email already exists.
     * @param  Workspace  $workspace  The workspace referenced in the invitation notification.
     * @return User The existing or newly created user.
     */
    public function handle(string $email, ?string $name, Workspace $workspace): User
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

        $token = Password::broker()->createToken($user);

        $user->notify(new WorkspaceInvitation($token, $workspace->name));

        return $user;
    }
}
