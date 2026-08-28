<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_an_api_token()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('tokens.store'), [
            'name' => 'CLI access',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'CLI access',
        ]);
    }

    public function test_user_can_delete_their_own_token()
    {
        $user = User::factory()->create();
        $token = $user->createToken('CLI access');

        $response = $this->actingAs($user)->delete(route('tokens.destroy', $token->accessToken->id));

        $response->assertRedirect();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
    }

    public function test_user_cannot_delete_another_users_token()
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $token = $otherUser->createToken('CLI access');

        $response = $this->actingAs($user)->delete(route('tokens.destroy', $token->accessToken->id));

        $response->assertNotFound();
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->id]);
    }
}
