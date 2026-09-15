<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;
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

    public function test_a_token_is_issued_with_the_default_lifetime_when_none_is_chosen()
    {
        Carbon::setTestNow('2026-09-15 12:00:00');
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('tokens.store'), ['name' => 'CLI access']);

        $this->assertTrue(
            PersonalAccessToken::query()->sole()->expires_at->equalTo(Carbon::now()->addDays(90)),
        );
    }

    public function test_user_can_choose_how_long_a_token_lasts()
    {
        Carbon::setTestNow('2026-09-15 12:00:00');
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('tokens.store'), [
            'name' => 'CLI access',
            'expires_in' => '30',
        ]);

        $this->assertTrue(
            PersonalAccessToken::query()->sole()->expires_at->equalTo(Carbon::now()->addDays(30)),
        );
    }

    public function test_a_token_that_never_expires_is_still_available_but_has_to_be_asked_for()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('tokens.store'), [
            'name' => 'Unattended scanner',
            'expires_in' => 'never',
        ]);

        $this->assertNull(PersonalAccessToken::query()->sole()->expires_at);
    }

    public function test_a_lifetime_that_is_not_offered_is_rejected()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('tokens.store'), [
            'name' => 'CLI access',
            'expires_in' => '9999',
        ]);

        $response->assertSessionHasErrors('expires_in');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_an_expired_token_is_refused()
    {
        $user = User::factory()->create();
        $token = $user->createToken('CLI access', ['*'], Carbon::now()->subMinute());

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->getJson('/api/user');

        $response->assertUnauthorized();
    }

    public function test_a_token_that_has_not_expired_is_accepted()
    {
        $user = User::factory()->create();
        $token = $user->createToken('CLI access', ['*'], Carbon::now()->addDay());

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->getJson('/api/user');

        $response->assertOk()->assertJsonPath('id', $user->id);
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
