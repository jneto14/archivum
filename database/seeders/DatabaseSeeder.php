<?php

namespace Database\Seeders;

use App\Models\User;
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
     * Creates the first admin user for a fresh self-hosted installation, so
     * there is a way to log in right after `php artisan migrate`. Safe to
     * re-run: an existing admin is left untouched.
     */
    public function run(): void
    {
        $email = (string) config('archivum.admin.email');
        $password = config('archivum.admin.password');

        $user = User::query()->where('email', $email)->first();

        if ($user) {
            $this->command->info("Admin user already exists ({$email}), skipping.");

            return;
        }

        $generatedPassword = null;

        if (! $password) {
            $generatedPassword = Str::password(20);
            $password = $generatedPassword;
        }

        User::query()->create([
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
    }
}
