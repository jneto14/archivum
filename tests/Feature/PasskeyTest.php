<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Passkey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

class PasskeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_passkey_generates_a_uuid_primary_key()
    {
        $user = User::factory()->create();

        $passkey = $user->passkeys()->create([
            'name' => 'Test Passkey',
            'credential_id' => 'credential-id',
            'credential' => ['type' => 'public-key'],
        ]);

        $this->assertInstanceOf(Passkey::class, $passkey);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $passkey->id,
        );
        $this->assertDatabaseHas('passkeys', ['id' => $passkey->id, 'user_id' => $user->id]);
    }

    public function test_the_passkey_login_options_route_is_reachable_and_rate_limited()
    {
        $this->skipUnlessFortifyHas(Features::passkeys());

        // The `passkeys` limiter keys on the submitted credential id, falling
        // back to the session when there isn't one — a guest asking for login
        // options is exactly that fallback, and getting the key wrong would
        // throttle every visitor against one shared bucket.
        $this->get(route('passkey.login-options'))->assertOk();

        $this->assertGuest();
    }
}
