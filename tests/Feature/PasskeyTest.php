<?php

namespace Tests\Feature;

use App\Models\Passkey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
