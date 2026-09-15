<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * The account behind a token: what it can read about itself, and change.
 */
class ProfileApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'name' => 'João',
            'timezone' => 'Europe/Lisbon',
            'locale' => 'pt',
        ]);

        $this->token = $this->user->createToken('CLI')->plainTextToken;
    }

    public function test_a_token_reads_its_own_profile()
    {
        $this->withToken($this->token)
            ->getJson('/api/v1/user')
            ->assertOk()
            ->assertJsonPath('data.id', $this->user->id)
            ->assertJsonPath('data.name', 'João')
            ->assertJsonPath('data.timezone', 'Europe/Lisbon')
            ->assertJsonPath('data.locale', 'pt')
            ->assertJsonPath('data.is_platform_admin', false);
    }

    public function test_a_token_can_change_its_own_name_timezone_and_language()
    {
        $this->withToken($this->token)
            ->patchJson('/api/v1/user', [
                'name' => 'João Neto',
                'email' => $this->user->email,
                'timezone' => 'UTC',
                'locale' => 'en',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'João Neto')
            ->assertJsonPath('data.timezone', 'UTC')
            ->assertJsonPath('data.locale', 'en');

        $this->assertSame('João Neto', $this->user->refresh()->name);
    }

    /**
     * The same thing the browser does: the new address has not been shown to
     * belong to anybody yet.
     */
    public function test_changing_the_email_clears_its_verification()
    {
        $this->assertNotNull($this->user->email_verified_at);

        $this->withToken($this->token)
            ->patchJson('/api/v1/user', [
                'name' => $this->user->name,
                'email' => 'moved@example.test',
            ])
            ->assertOk()
            ->assertJsonPath('data.email', 'moved@example.test')
            ->assertJsonPath('data.email_verified_at', null);

        $this->assertNull($this->user->refresh()->email_verified_at);
    }

    public function test_an_email_somebody_else_holds_is_refused()
    {
        $other = User::factory()->create();

        $this->withToken($this->token)
            ->patchJson('/api/v1/user', ['name' => 'João', 'email' => $other->email])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['email']]);
    }

    public function test_a_language_the_installation_does_not_carry_is_refused()
    {
        $this->withToken($this->token)
            ->patchJson('/api/v1/user', [
                'name' => 'João',
                'email' => $this->user->email,
                'locale' => 'kl',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['locale']]);
    }

    /**
     * The Form Request is the browser's, so the demo installation's refusal to
     * let its own login address be changed holds here without being restated.
     */
    public function test_a_demo_installation_refuses_an_email_change()
    {
        Config::set('archivum.demo.enabled', true);

        $this->withToken($this->token)
            ->patchJson('/api/v1/user', ['name' => 'João', 'email' => 'moved@example.test'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['email']]);

        $this->assertNotSame('moved@example.test', $this->user->refresh()->email);
    }

    public function test_a_token_cannot_change_anybody_else_s_profile()
    {
        $other = User::factory()->create(['name' => 'Someone else']);

        $this->withToken($this->token)
            ->patchJson("/api/v1/users/{$other->id}", ['name' => 'Hijacked'])
            ->assertNotFound();

        $this->assertSame('Someone else', $other->refresh()->name);
    }

    public function test_deleting_the_account_and_changing_the_password_are_not_on_the_api()
    {
        $this->withToken($this->token)->deleteJson('/api/v1/user')->assertStatus(405);
        $this->withToken($this->token)->putJson('/api/v1/user/password')->assertNotFound();
    }
}
