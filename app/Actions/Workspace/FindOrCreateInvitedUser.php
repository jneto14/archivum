<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
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
     * @param string $email The email address to look up or invite.
     * @param string|null $name The name to use if a new user is created; ignored if a user with $email already exists.
     * @param Workspace $workspace The workspace referenced in the invitation notification.
     *
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

        $this->releaseAutoJoinedMembership($user, $workspace);

        $token = Password::broker()->createToken($user);

        $user->notify(new WorkspaceInvitation($token, $workspace->name));

        return $user;
    }

    /**
     * Drop the membership the `User::created` listener may have just created.
     *
     * On a single-workspace installation that listener joins every brand-new
     * user to the sole workspace, so that a user created outside any workspace
     * flow isn't locked out (see `AppServiceProvider`). An invitation is a
     * workspace flow: the caller assigns the role the admin actually chose,
     * moments after this returns. Leaving the auto-joined row in place makes
     * that caller see the invitation as an existing member, so the invitation
     * fails with "already a member" while the invited person silently keeps
     * the default role.
     *
     * @param User $user The user just created for this invitation.
     * @param Workspace $workspace The workspace the invitation is for.
     *
     * @return void No return value; deletes the auto-joined membership, if there is one.
     */
    private function releaseAutoJoinedMembership(User $user, Workspace $workspace): void
    {
        WorkspaceUser::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->delete();
    }
}
