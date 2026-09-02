<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLimit;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * What a public demo stops a visitor doing.
 *
 * Anyone can sign in with the credentials printed on the login screen, and the
 * demo account is both a workspace admin and a platform admin — so every
 * visitor arrives holding the keys. None of this is about damage: the nightly
 * reset repairs everything. It is about the hours in between, where one visitor
 * can leave the demo useless to the next.
 *
 * Two shapes of that. Being locked out — changing the password, the email or
 * deleting the account all make the printed credentials wrong. And being left
 * nothing to look at — deleting the workspace empties the demo, and raising the
 * limits removes the ceiling that stops an upload spree filling the volume.
 *
 * The other half of each restriction — that it does nothing on an ordinary
 * installation — is proved by the suites for those features (WorkspaceDelete,
 * WorkspaceLimit, ProfileUpdate), which all run with DEMO_MODE off and would
 * go red the moment one of these guards leaked out of demo mode.
 */
class DemoRestrictionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_visitor_cannot_change_the_password_and_lock_everyone_out()
    {
        config()->set('archivum.demo.enabled', true);

        $user = User::factory()->create(['password' => Hash::make('password')]);

        $this
            ->actingAs($user)
            ->from(route('security.edit'))
            ->put(route('user-password.update'), [
                'current_password' => 'password',
                'password' => 'Str0ng!Passw0rd',
                'password_confirmation' => 'Str0ng!Passw0rd',
            ])
            ->assertSessionHasErrors('demo');

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    public function test_an_ordinary_installation_still_changes_passwords()
    {
        config()->set('archivum.demo.enabled', false);

        $user = User::factory()->create(['password' => Hash::make('password')]);

        $this
            ->actingAs($user)
            ->from(route('security.edit'))
            ->put(route('user-password.update'), [
                'current_password' => 'password',
                'password' => 'Str0ng!Passw0rd',
                'password_confirmation' => 'Str0ng!Passw0rd',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('Str0ng!Passw0rd', $user->refresh()->password));
    }

    /**
     * A second workspace exists so that the block being tested is this one:
     * with a single workspace, DeleteWorkspace refuses anyway, and the test
     * would pass on an installation with no demo restrictions at all.
     */
    public function test_a_visitor_cannot_delete_the_workspace_and_empty_the_demo()
    {
        config()->set('archivum.demo.enabled', true);

        Workspace::factory()->create();
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);

        $this
            ->actingAs($admin->user)
            ->from(route('workspaces.settings.show', $workspace))
            ->delete(route('workspaces.destroy', $workspace))
            ->assertSessionHasErrors('demo');

        $this->assertDatabaseHas('workspaces', ['id' => $workspace->id]);
    }

    public function test_a_visitor_cannot_create_workspaces()
    {
        config()->set('archivum.demo.enabled', true);

        $platformAdmin = User::factory()->create(['is_platform_admin' => true]);

        $this
            ->actingAs($platformAdmin)
            ->from(route('workspaces.index'))
            ->post(route('workspaces.store'), ['name' => 'Mine now'])
            ->assertSessionHasErrors('demo');

        $this->assertDatabaseMissing('workspaces', ['name' => 'Mine now']);
    }

    /**
     * The limits are the only thing standing between a demo and a full disk,
     * and the demo account is a platform admin, so without this the visitor
     * can raise the ceiling they are being held under.
     */
    public function test_a_visitor_cannot_raise_the_workspace_limits()
    {
        config()->set('archivum.demo.enabled', true);

        $workspace = Workspace::factory()->create();
        WorkspaceLimit::factory()->for($workspace)->create(['documents' => 50]);
        $platformAdmin = User::factory()->create(['is_platform_admin' => true]);

        $this
            ->actingAs($platformAdmin)
            ->from(route('workspaces.settings.show', $workspace))
            ->patch(route('workspaces.limits.update', $workspace), [
                'storage_bytes' => null,
                'users' => null,
                'documents' => null,
                'attachments' => null,
            ])
            ->assertSessionHasErrors('demo');

        $this->assertSame(50, $workspace->limits()->sole()->documents);
    }

    public function test_a_visitor_cannot_change_the_email_the_login_screen_advertises()
    {
        config()->set('archivum.demo.enabled', true);

        $user = User::factory()->create(['email' => 'demo@archivum.example']);

        $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => 'mine@example.com',
            ])
            ->assertSessionHasErrors(['email' => __('demo.action_unavailable')]);

        $this->assertSame('demo@archivum.example', $user->refresh()->email);
    }

    /**
     * The email is refused in validation rather than by blocking the route,
     * because the same form carries the language — and trying the interface in
     * another language is one of the things a demo exists for.
     */
    public function test_a_visitor_can_still_change_the_language()
    {
        config()->set('archivum.demo.enabled', true);

        $user = User::factory()->create(['locale' => 'en']);

        $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => $user->email,
                'locale' => 'pt',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('pt', $user->refresh()->locale);
    }

    public function test_a_visitor_cannot_delete_the_demo_account()
    {
        config()->set('archivum.demo.enabled', true);

        $user = User::factory()->create(['password' => Hash::make('password')]);

        $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasErrors('demo');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    /**
     * Redirected at the transport, not at each sender: invitations and export
     * links both go out on their own, and a per-feature switch is one somebody
     * forgets to add to the next feature that sends something.
     */
    public function test_a_demo_sends_no_mail_whatever_asks()
    {
        putenv('DEMO_MODE=true');
        $_ENV['DEMO_MODE'] = $_SERVER['DEMO_MODE'] = 'true';

        $this->refreshApplication();

        $this->assertSame('log', config('mail.default'));
    }

    /**
     * Restore the suite-wide default rather than unsetting it: removing the
     * variable takes phpunit.xml's own `DEMO_MODE=false` with it, and later
     * tests in the process then boot with it absent.
     */
    protected function tearDown(): void
    {
        putenv('DEMO_MODE=false');
        $_ENV['DEMO_MODE'] = $_SERVER['DEMO_MODE'] = 'false';

        parent::tearDown();
    }

    public function test_an_ordinary_installation_keeps_its_configured_mailer()
    {
        $this->assertNotSame('log', config('mail.default'));
    }
}
