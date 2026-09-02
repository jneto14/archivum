<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * What a public demo stops a visitor doing.
 *
 * Anyone can sign in with the credentials printed on the login screen, which
 * makes two ordinary features into problems: the first visitor to change the
 * password locks every later one out until the next reset, and any feature that
 * sends mail sends it, from a demo, to a real stranger's inbox.
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
