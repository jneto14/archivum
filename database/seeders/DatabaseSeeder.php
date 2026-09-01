<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Creates the first admin user and default workspace for a fresh
     * self-hosted installation, so there is a way to log in and something
     * to log in to right after `php artisan migrate`. Safe to re-run.
     */
    public function run(): void
    {
        $user = $this->seedAdminUser();
        $this->seedDefaultWorkspace($user);
    }

    private function seedAdminUser(): User
    {
        $email = (string) config('archivum.admin.email');
        $password = config('archivum.admin.password');

        $user = User::query()->where('email', $email)->first();

        if ($user) {
            $this->command->info("Admin user already exists ({$email}), skipping.");

            return $this->promoteToPlatformAdmin($user);
        }

        $generatedPassword = null;

        if (!$password) {
            $generatedPassword = Str::password(20);
            $password = $generatedPassword;
        }

        $user = User::query()->create([
            'name' => config('archivum.admin.name'),
            'email' => $email,
            'password' => Hash::make($password),
            'email_verified_at' => now(),
        ]);

        if ($generatedPassword) {
            $this->command->warn("Created admin user {$email} with generated password: {$generatedPassword}");
            $this->command->warn('Store it now — it will not be shown again. Set ADMIN_PASSWORD in .env to choose your own.');
        } else {
            $this->command->info("Created admin user {$email}.");
        }

        return $this->promoteToPlatformAdmin($user);
    }

    /**
     * Make sure the bootstrap admin carries the platform admin flag.
     *
     * Without it a fresh installation has nobody who can create a workspace or
     * change its limits — `WorkspaceController::store` and the limits form
     * both gate on this flag — so the first account could log in and then do
     * very little.
     *
     * Applied to an existing user as well as a new one, because an
     * installation seeded before this has an admin without the flag and
     * re-running the seeder is the documented way to repair the bootstrap
     * account. Assigned rather than passed to `create()`: `db:seed` happens to
     * run unguarded, but the flag is deliberately not fillable, and this is a
     * privilege bit that should not depend on that.
     *
     * `platform-admin:grant --revoke` still takes it away.
     *
     * @param User $user The user configured as `archivum.admin.email`.
     *
     * @return User The same user, with the flag set.
     */
    private function promoteToPlatformAdmin(User $user): User
    {
        if ($user->is_platform_admin) {
            return $user;
        }

        $user->is_platform_admin = true;
        $user->save();

        $this->command->info("Granted platform admin to {$user->email}.");

        return $user;
    }

    private function seedDefaultWorkspace(User $admin): void
    {
        $workspace = Workspace::query()->firstOrCreate([
            'name' => config('archivum.default_workspace_name'),
        ]);

        WorkspaceUser::query()->firstOrCreate(
            ['workspace_id' => $workspace->id, 'user_id' => $admin->id],
            ['role' => WorkspaceRole::Admin],
        );

        $this->command->info("Default workspace \"{$workspace->name}\" ready.");
    }
}
