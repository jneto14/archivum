<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GrantPlatformAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_grants_platform_admin_by_email()
    {
        $user = User::factory()->create(['is_platform_admin' => false]);

        $this->artisan('platform-admin:grant', ['email' => $user->email])
            ->assertExitCode(0);

        $this->assertTrue($user->fresh()->is_platform_admin);
    }

    public function test_it_revokes_platform_admin_with_the_revoke_option()
    {
        $user = User::factory()->create(['is_platform_admin' => true]);

        $this->artisan('platform-admin:grant', ['email' => $user->email, '--revoke' => true])
            ->assertExitCode(0);

        $this->assertFalse($user->fresh()->is_platform_admin);
    }

    public function test_it_fails_clearly_for_an_unknown_email()
    {
        $this->artisan('platform-admin:grant', ['email' => 'nobody@example.com'])
            ->assertExitCode(1);
    }
}
