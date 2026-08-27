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

            return $user;
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
