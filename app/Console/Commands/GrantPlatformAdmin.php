<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('platform-admin:grant {email : The email address of the user} {--revoke : Revoke platform admin instead of granting it}')]
#[Description('Grant or revoke the platform admin flag for a user, by email')]
class GrantPlatformAdmin extends Command
{
    /**
     * Execute the console command.
     *
     * @return int The command's exit code: SUCCESS if the user was found and updated, FAILURE if no user matched the given email.
     */
    public function handle(): int
    {
        /** @var string $email */
        $email = $this->argument('email');

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No user found with email [{$email}].");

            return self::FAILURE;
        }

        $revoke = (bool) $this->option('revoke');

        $user->is_platform_admin = !$revoke;
        $user->save();

        $this->info($revoke
            ? "Revoked platform admin from {$user->email}."
            : "Granted platform admin to {$user->email}.");

        return self::SUCCESS;
    }
}
