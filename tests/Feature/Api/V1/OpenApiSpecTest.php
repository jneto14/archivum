<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Support\OpenApiSpec;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Keeps the spec worth reading.
 *
 * It is built from the route table on every request, so it cannot invent an
 * endpoint or fall behind one. What it can still do is describe them badly —
 * a dangling reference a client generator reports three steps from the cause,
 * a group grown into a list nobody skips, a summary that is a sentence — and
 * none of that fails loudly on its own.
 */
class OpenApiSpecTest extends TestCase
{
    /**
     * @return array<string, mixed> The spec, built the way the endpoint builds it.
     */
    private function spec(): array
    {
        return app(OpenApiSpec::class)->build();
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
                    mb_strtoupper($method) . " {$path} has no entry in the catalogue.",
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
     * A summary is a name, not a sentence. A client lists these in a sidebar
     * that truncates at around twenty characters, so prose there arrives as
     * "This document, poin…" and tells nobody anything.
     */
    public function test_every_summary_reads_as_a_name()
    {
        $spec = $this->spec();

        foreach ($spec['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                $where = mb_strtoupper($method) . " {$path}";
                $summary = $operation['summary'];

                $this->assertLessThanOrEqual(
                    32,
                    mb_strlen($summary),
                    "{$where} has a summary too long to read in a list: \"{$summary}\". Put the reasoning in `description`.",
                );

                $this->assertStringEndsNotWith('.', $summary, "{$where}'s summary is a sentence, not a name.");
            }
        }
    }

    /**
     * The order the spec is written in is the order a client lists it in, so
     * it is grouped rather than sorted by URL.
     */
    public function test_operations_are_grouped_by_tag_rather_than_scattered()
    {
        $spec = $this->spec();

        $seen = [];

        foreach ($spec['paths'] as $path => $methods) {
            foreach ($methods as $operation) {
                $tag = $operation['tags'][0];

                if ($seen !== [] && end($seen) === $tag) {
                    continue;
                }

                $this->assertNotContains(
                    $tag,
                    $seen,
                    "{$path} returns to the {$tag} group after leaving it; the catalogue's order has drifted.",
                );

                $seen[] = $tag;
            }
        }
    }

    /**
     * A folder holding fifteen operations is a list somebody has to read
     * rather than a group they can skip. Splitting one is cheap; noticing it
     * needed splitting is what this is for.
     */
    public function test_no_tag_grows_into_a_list_nobody_reads()
    {
        $spec = $this->spec();

        $counts = [];

        foreach ($spec['paths'] as $methods) {
            foreach ($methods as $operation) {
                $tag = $operation['tags'][0];
                $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            }
        }

        foreach ($counts as $tag => $count) {
            $this->assertLessThanOrEqual(
                8,
                $count,
                "{$tag} holds {$count} operations. Split it — a client renders one folder per tag.",
            );
        }
    }

    /**
     * A folder name truncates in a sidebar at about the width an operation
     * does, and clients sort tags themselves, so the first word is the only
     * lever over which groups end up next to each other.
     */
    public function test_every_tag_name_fits_where_it_is_read()
    {
        $spec = $this->spec();

        foreach ($spec['tags'] as $tag) {
            $this->assertLessThanOrEqual(
                20,
                mb_strlen($tag['name']),
                "The tag \"{$tag['name']}\" is too long to read in a sidebar.",
            );
        }
    }

    public function test_every_tag_declared_is_used()
    {
        $spec = $this->spec();

        $used = [];

        foreach ($spec['paths'] as $methods) {
            foreach ($methods as $operation) {
                $used = [...$used, ...$operation['tags']];
            }
        }

        foreach ($spec['tags'] as $tag) {
            $this->assertContains(
                $tag['name'],
                $used,
                "The tag \"{$tag['name']}\" is declared and files nothing.",
            );
        }
    }

    public function test_every_tag_used_is_declared()
    {
        $spec = $this->spec();

        $declared = array_column($spec['tags'], 'name');

        foreach ($spec['paths'] as $path => $methods) {
            foreach ($methods as $operation) {
                foreach ($operation['tags'] as $tag) {
                    $this->assertContains($tag, $declared, "{$path} is filed under an undeclared tag: {$tag}.");
                }
            }
        }
    }

    public function test_path_parameters_show_the_shape_that_goes_in_them()
    {
        $spec = $this->spec();

        foreach ($spec['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                foreach ($operation['parameters'] as $parameter) {
                    if (($parameter['in'] ?? null) !== 'path') {
                        continue;
                    }

                    $this->assertArrayHasKey(
                        'example',
                        $parameter,
                        mb_strtoupper($method) . " {$path} leaves `{$parameter['name']}` without an example, so an imported collection shows an empty box.",
                    );
                }
            }
        }
    }

    /**
     * Every installation is served from its own host, so the document the
     * builder produces names none of them.
     */
    public function test_the_server_url_is_a_variable_rather_than_somebody_s_host()
    {
        $spec = $this->spec();

        $this->assertSame('{origin}/api/v1', $spec['servers'][0]['url']);
        $this->assertStringNotContainsString((string) config('app.url'), json_encode($spec['servers']));
    }

    /**
     * The builder cannot name a host; the installation answering the request
     * can, and that substitution is the reason this is served rather than
     * written out once.
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
     * The point of building it per request: a route that did not exist when
     * the application booted this test is in the document all the same.
     */
    public function test_the_served_spec_describes_the_application_that_is_running()
    {
        $this->getJson('/api/v1/openapi.json')
            ->assertOk()
            ->assertJsonMissingPath('paths./probe');

        Route::get('api/v1/probe', fn () => null)->name('api.v1.probe');

        $this->getJson('/api/v1/openapi.json')
            ->assertOk()
            ->assertJsonPath('paths./probe.get.operationId', 'probe');
    }

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
     * A description that is written, reviewed and then dropped on the way out
     * is worse than one nobody wrote: the catalogue reads as complete and the
     * document a client actually gets is not.
     */
    public function test_every_catalogued_description_reaches_the_document()
    {
        $spec = $this->spec();

        $described = [];

        foreach ($spec['paths'] as $methods) {
            foreach ($methods as $operation) {
                if (isset($operation['description'])) {
                    $described[$operation['operationId']] = $operation['description'];
                }
            }
        }

        $catalogue = (new ReflectionMethod(OpenApiSpec::class, 'catalogue'))
            ->invoke(app(OpenApiSpec::class));

        $catalogued = 0;

        foreach ($catalogue as $key => $entry) {
            if (!isset($entry['description'])) {
                continue;
            }

            $catalogued++;

            $this->assertSame(
                $entry['description'],
                $described[$key] ?? null,
                "{$key} is described in the catalogue and the description does not reach the document.",
            );
        }

        $this->assertGreaterThan(0, $catalogued, 'Nothing in the catalogue carries a description.');
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
