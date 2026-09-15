<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The guarantees every /api/v1 route inherits, asserted once here rather than
 * repeated per area: who gets in, who does not, and what it costs.
 */
class ApiAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_request_without_a_token_is_refused()
    {
        $response = $this->getJson('/api/v1/user');

        $response->assertUnauthorized();
    }

    public function test_a_token_identifies_its_owner()
    {
        $user = User::factory()->create();

        $response = $this->withToken($user->createToken('CLI')->plainTextToken)
            ->getJson('/api/v1/user');

        $response->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_an_expired_token_is_refused()
    {
        $user = User::factory()->create();
        $token = $user->createToken('CLI', ['*'], Carbon::now()->subMinute());

        $response = $this->withToken($token->plainTextToken)->getJson('/api/v1/user');

        $response->assertUnauthorized();
    }

    /**
     * The API is bearer-token only, and this is the assertion that keeps it
     * that way. `config('sanctum.guard')` is empty precisely so a session
     * cannot authenticate here: /api carries no CSRF middleware, having never
     * been meant to be reached with a cookie, so a session that did
     * authenticate would be a cross-site request away from writing.
     */
    public function test_a_session_does_not_authenticate_an_api_request()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/user');

        $response->assertUnauthorized();
    }

    /**
     * Laravel's own message is "No query results for model
     * [App\\Models\\Document] 01a0…", which describes the code behind the route
     * and is also what a client is told when the row exists and is somebody
     * else's — where naming it would confirm it exists.
     */
    public function test_a_404_does_not_name_the_model_it_could_not_find()
    {
        $user = User::factory()->create();

        $response = $this->withToken($user->createToken('CLI')->plainTextToken)
            ->getJson('/api/v1/documents/01a0a15b-0000-7000-8000-000000000000');

        $response->assertNotFound()->assertExactJson(['message' => 'Not found.']);
        $this->assertStringNotContainsString('App\\Models', $response->getContent());
    }

    public function test_an_unroutable_api_path_answers_the_same_way()
    {
        $user = User::factory()->create();

        $this->withToken($user->createToken('CLI')->plainTextToken)
            ->getJson('/api/v1/nothing-here')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Not found.']);
    }

    public function test_the_rate_limit_answers_once_it_is_reached()
    {
        config(['archivum.api.rate_limit' => 2]);
        $user = User::factory()->create();
        $token = $user->createToken('CLI')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/user')->assertOk();
        $this->withToken($token)->getJson('/api/v1/user')->assertOk();

        $this->withToken($token)->getJson('/api/v1/user')->assertStatus(429);
    }

    /**
     * A self-hosted installation running a bulk import is its own only
     * neighbour, so the limit can be lifted entirely.
     */
    public function test_a_rate_limit_of_zero_lifts_the_limit()
    {
        config(['archivum.api.rate_limit' => 0]);
        $user = User::factory()->create();
        $token = $user->createToken('CLI')->plainTextToken;

        foreach (range(1, 5) as $ignored) {
            $this->withToken($token)->getJson('/api/v1/user')->assertOk();
        }
    }
}
