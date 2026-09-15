<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Console\Commands\GenerateOpenApiSpec;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Keeps docs/openapi.json honest.
 *
 * The spec is generated from the route table, so it cannot invent an endpoint.
 * What it can do is fall behind, which is what these assert against: a route
 * added without regenerating fails here rather than going quietly
 * undocumented, and a spec whose references do not resolve fails before a
 * client generator finds out the hard way.
 */
class OpenApiSpecTest extends TestCase
{
    /**
     * @return array<string, mixed> The committed spec.
     */
    private function spec(): array
    {
        $path = base_path(GenerateOpenApiSpec::PATH);

        $this->assertFileExists($path, 'Run `php artisan api:openapi`.');

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_the_committed_spec_is_what_the_command_produces()
    {
        $this->artisan('api:openapi', ['--check' => true])->assertSuccessful();
    }

    public function test_every_versioned_route_is_described()
    {
        $spec = $this->spec();

        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();

            if (!str_starts_with($name, 'api.v1.')) {
                continue;
            }

            $path = '/' . Str::after($route->uri(), 'api/v1/');

            $this->assertArrayHasKey($path, $spec['paths'], "{$path} is missing from the spec.");

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $this->assertArrayHasKey(
                    mb_strtolower($method),
                    $spec['paths'][$path],
                    "{$method} {$path} is missing from the spec.",
                );
            }
        }
    }

    public function test_the_spec_describes_no_route_that_does_not_exist()
    {
        $spec = $this->spec();

        $uris = [];

        foreach (Route::getRoutes() as $route) {
            if (str_starts_with((string) $route->getName(), 'api.v1.')) {
                $uris[] = '/' . Str::after($route->uri(), 'api/v1/');
            }
        }

        foreach (array_keys($spec['paths']) as $path) {
            $this->assertContains($path, $uris, "{$path} is in the spec and not in the route table.");
        }
    }

    /**
     * A dangling reference is the failure a client generator reports as
     * something unhelpful, three steps from the cause.
     */
    public function test_every_reference_resolves()
    {
        $spec = $this->spec();

        foreach ($this->referencesIn($spec) as $reference) {
            $node = $spec;

            foreach (explode('/', mb_ltrim($reference, '#/')) as $segment) {
                $this->assertIsArray($node, "{$reference} does not resolve.");
                $this->assertArrayHasKey($segment, $node, "{$reference} does not resolve.");
                $node = $node[$segment];
            }
        }
    }

    public function test_every_operation_is_catalogued_rather_than_left_generic()
    {
        $spec = $this->spec();

        foreach ($spec['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                $this->assertNotSame(
                    $operation['operationId'],
                    $operation['summary'],
                    mb_strtoupper($method) . " {$path} has no entry in the command's catalogue.",
                );
            }
        }
    }

    /**
     * Everything but the spec itself, which is public because it describes how
     * to authenticate and is no use to a client that must already have.
     */
    public function test_every_operation_requires_a_token()
    {
        $spec = $this->spec();

        $this->assertSame([['bearerAuth' => []]], $spec['security']);

        foreach ($spec['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                if ($path === '/openapi.json') {
                    $this->assertSame([], $operation['security']);

                    continue;
                }

                $this->assertArrayNotHasKey(
                    'security',
                    $operation,
                    mb_strtoupper($method) . " {$path} opts out of the document's security requirement.",
                );

                $this->assertArrayHasKey(
                    '401',
                    $operation['responses'],
                    mb_strtoupper($method) . " {$path} does not say what an untokened request gets.",
                );
            }
        }
    }

    /**
     * Committed to a repository every installation deploys from its own host,
     * so a concrete origin in here would be whichever machine last ran the
     * command.
     */
    public function test_the_server_url_is_a_variable_rather_than_somebody_s_host()
    {
        $spec = $this->spec();

        $this->assertSame('{origin}/api/v1', $spec['servers'][0]['url']);
        $this->assertStringNotContainsString((string) config('app.url'), json_encode($spec['servers']));
    }

    /**
     * The committed file cannot name a host; a running installation can, and
     * that substitution is the reason to serve it rather than only ship it.
     */
    public function test_the_served_spec_points_at_this_installation()
    {
        $response = $this->getJson('/api/v1/openapi.json');

        $response->assertOk()
            ->assertJsonPath('openapi', '3.1.0')
            ->assertJsonPath('servers.0.url', url('/api/v1'));

        $this->assertArrayNotHasKey('variables', $response->json('servers.0'));
    }

    /**
     * It describes how to authenticate, so needing a token to read it would be
     * a bootstrapping problem — and it is already public in the repository.
     */
    public function test_the_spec_is_served_without_a_token()
    {
        $this->assertGuest();

        $this->getJson('/api/v1/openapi.json')->assertOk();
    }

    public function test_the_spec_describes_itself()
    {
        $spec = $this->spec();

        $this->assertArrayHasKey('/openapi.json', $spec['paths']);
        $this->assertSame([], $spec['paths']['/openapi.json']['get']['security']);
    }

    /**
     * @param array<string, mixed> $node The node to search.
     *
     * @return array<int, string> Every `$ref` beneath it.
     */
    private function referencesIn(array $node): array
    {
        $found = [];

        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value)) {
                $found[] = $value;
            } elseif (is_array($value)) {
                $found = [...$found, ...$this->referencesIn($value)];
            }
        }

        return $found;
    }
}
