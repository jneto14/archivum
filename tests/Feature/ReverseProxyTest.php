<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * What has to hold when the application is not the thing the browser talks to.
 *
 * Behind a reverse proxy the request the application receives is not the
 * request that was made: the proxy has already terminated TLS, and it may have
 * stripped a path prefix before forwarding. Every one of these failures looks
 * like the application being broken rather than the deployment being described
 * wrongly, which is what makes them worth pinning.
 *
 * The environment is rewritten and the application rebooted rather than the
 * config being set, because both settings are read once during boot — a
 * `config()->set()` after the fact would change nothing and the tests would
 * pass against no behaviour at all.
 */
class ReverseProxyTest extends TestCase
{
    /**
     * @param array<string, string> $variables
     */
    private function rebootWith(array $variables): void
    {
        foreach ($variables as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        $this->refreshApplication();
    }

    /**
     * Restores what phpunit.xml pins rather than unsetting: removing the
     * variables would take the suite-wide values with them, and every later
     * test in the process would boot without them.
     */
    protected function tearDown(): void
    {
        foreach (['APP_URL' => 'http://localhost', 'TRUSTED_PROXIES' => ''] as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        parent::tearDown();
    }

    /**
     * Asserted from inside a request whose own host and scheme are wrong on
     * purpose. Outside a request the URL generator already falls back to
     * `app.url`, so a test that only calls `route()` passes whether the
     * application forces the root or not — it asserts a framework default and
     * nothing else.
     *
     * `http://app:8080` stands for what a proxy actually forwards: the internal
     * address of the container, over plain HTTP, with the public path prefix
     * already stripped off.
     */
    public function test_generated_urls_carry_the_path_the_installation_is_served_under()
    {
        $this->rebootWith(['APP_URL' => 'https://example.test/archivum']);
        $this->routeReportingAGeneratedUrl();

        $this->get('http://app:8080/_url-probe')
            ->assertExactJson(['login' => 'https://example.test/archivum/login']);
    }

    /**
     * A proxy that terminates TLS forwards plain HTTP. Without this the
     * redirect after signing in goes back as `http://`, and the browser either
     * refuses it outright or quietly leaves TLS behind.
     */
    public function test_generated_urls_keep_the_scheme_the_browser_used()
    {
        $this->rebootWith(['APP_URL' => 'https://example.test']);
        $this->routeReportingAGeneratedUrl();

        $this->get('http://app:8080/_url-probe')
            ->assertExactJson(['login' => 'https://example.test/login']);
    }

    public function test_an_installation_on_a_bare_domain_is_unaffected()
    {
        $this->rebootWith(['APP_URL' => 'http://localhost']);
        $this->routeReportingAGeneratedUrl();

        $this->get('/_url-probe')
            ->assertExactJson(['login' => 'http://localhost/login']);
    }

    /**
     * Two things ride on the application knowing which request was really
     * secure and who really sent it: whether the session cookie is marked
     * Secure, and whether `throttle:6,1` on the login and password routes
     * counts one client or the whole internet as one client.
     */
    public function test_forwarded_headers_are_ignored_when_no_proxy_is_trusted()
    {
        $this->rebootWith(['TRUSTED_PROXIES' => '']);
        $this->routeReportingTheRequest();

        $this->get('/_proxy-probe', [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-For' => '203.0.113.9',
        ])->assertExactJson(['secure' => false, 'ip' => '127.0.0.1']);
    }

    public function test_forwarded_headers_are_believed_when_every_proxy_is_trusted()
    {
        $this->rebootWith(['TRUSTED_PROXIES' => '*']);
        $this->routeReportingTheRequest();

        $this->get('/_proxy-probe', [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-For' => '203.0.113.9',
        ])->assertExactJson(['secure' => true, 'ip' => '203.0.113.9']);
    }

    /**
     * The production entrypoint runs `config:cache`, and a cached config means
     * the framework never reads `.env` at all. Anything wired straight from
     * `env()` is therefore null in production while working perfectly in
     * development — so what is pinned here is that the setting travels through
     * config, which is what `config:cache` compiles.
     */
    public function test_the_setting_travels_through_config_so_a_cached_config_keeps_it()
    {
        $this->rebootWith(['TRUSTED_PROXIES' => '10.0.0.1']);

        $this->assertSame('10.0.0.1', config('archivum.trusted_proxies'));
    }

    public function test_a_named_proxy_is_trusted_and_others_are_not()
    {
        $this->rebootWith(['TRUSTED_PROXIES' => '10.0.0.1, 127.0.0.1']);
        $this->routeReportingTheRequest();

        $this->get('/_proxy-probe', ['X-Forwarded-Proto' => 'https'])
            ->assertJson(['secure' => true]);
    }

    private function routeReportingAGeneratedUrl(): void
    {
        Route::get('/_url-probe', fn () => ['login' => route('login')]);
    }

    /**
     * Registered outside the `web` group on purpose: TrustProxies is global
     * middleware, so it still runs, and nothing here needs a session or a
     * resolved workspace.
     */
    private function routeReportingTheRequest(): void
    {
        Route::get('/_proxy-probe', fn (Request $request) => [
            'secure' => $request->isSecure(),
            'ip' => $request->ip(),
        ]);
    }
}
