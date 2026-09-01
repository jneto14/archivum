<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The seeder is what a fresh self-hosted install runs to get an account.
 *
 * The account it makes has to be a platform admin: creating a workspace and
 * changing workspace limits both gate on that flag, so without it the first
 * and only user can log in and then do almost nothing.
 */
class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('archivum.admin.email', 'owner@example.test');
        config()->set('archivum.admin.password', 'a-known-password');
        config()->set('archivum.admin.name', 'Owner');
    }

    public function test_it_creates_a_platform_admin_and_a_workspace_to_use_it_in()
    {
        $this->seed();

        $user = User::query()->where('email', 'owner@example.test')->firstOrFail();

        $this->assertTrue($user->is_platform_admin);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(1, Workspace::query()->count());
    }

    public function test_it_grants_the_flag_to_an_admin_that_predates_it()
    {
        $user = User::factory()->create([
            'email' => 'owner@example.test',
            'is_platform_admin' => false,
        ]);

        $this->seed();

        $this->assertTrue($user->fresh()->is_platform_admin);
    }

    public function test_re_running_it_creates_nothing_twice()
    {
        $this->seed();
        $this->seed();

        $this->assertSame(1, User::query()->where('email', 'owner@example.test')->count());
        $this->assertSame(1, Workspace::query()->count());
    }
}
