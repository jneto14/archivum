<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\FontStyles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
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
     * The regression this exists for: forcing the URL root from a service
     * provider also forces it on the command line, and `wayfinder:generate`
     * runs there during the asset build, writing every route URL into the
     * JavaScript bundle. A forced root makes them absolute — so the published
     * image, built from `.env.example`, would ship a front end posting to
     * `http://localhost` on every installation in the world.
     *
     * That is why the root is forced in middleware instead. This asserts the
     * generator still emits paths, and that it does carry the prefix from
     * APP_URL — which is what the browser-side rewrite relies on to know a
     * build already prefixed is not prefixed twice.
     */
    public function test_the_asset_build_does_not_bake_this_installations_hostname_into_the_bundle()
    {
        $this->rebootWith(['APP_URL' => 'https://example.test/archivum']);

        $path = 'storage/framework/testing/wayfinder';
        File::deleteDirectory(base_path($path));

        $this->artisan('wayfinder:generate', ['--path' => $path, '--skip-actions' => true])
            ->assertSuccessful();

        $generated = File::get(base_path($path . '/routes/index.ts'));
        File::deleteDirectory(base_path($path));

        $this->assertStringNotContainsString('https://example.test', $generated);
        $this->assertStringContainsString("url: '/archivum/login'", $generated);
    }

    /**
     * The bundle's route URLs were compiled without the prefix, so the page has
     * to tell it. Read from a meta tag rather than the Inertia props because it
     * has to be known before the first route module is used.
     */
    public function test_the_page_declares_the_prefix_for_the_javascript_bundle()
    {
        $this->rebootWith(['APP_URL' => 'https://example.test/archivum']);

        $this->get('http://app:8080/login')
            ->assertSee('<meta name="app-path-prefix" content="/archivum">', false);
    }

    public function test_the_declared_prefix_is_empty_on_a_bare_domain()
    {
        $this->rebootWith(['APP_URL' => 'https://example.test']);

        $this->get('/login')->assertSee('<meta name="app-path-prefix" content="">', false);
    }

    /**
     * Inertia takes the address bar from the URL it reports, not from the
     * browser. The proxy strips the prefix before the request arrives, so
     * without this the first navigation would rewrite the address bar to the
     * wrong root and a reload would land on nothing.
     */
    public function test_inertia_reports_the_current_page_under_the_prefix()
    {
        $this->rebootWith(['APP_URL' => 'https://example.test/archivum']);

        $this->get('http://app:8080/login?redirect=1')
            ->assertSee('"url":"\/archivum\/login?redirect=1"', false);
    }

    /**
     * @param array{0: string, 1: string} $case
     */
    #[DataProvider('appUrls')]
    public function test_the_prefix_is_read_out_of_app_url(string $appUrl, string $expected)
    {
        $this->rebootWith(['APP_URL' => $appUrl]);

        $this->assertSame($expected, config('archivum.path_prefix'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function appUrls(): array
    {
        return [
            'a bare domain' => ['https://example.test', ''],
            'a trailing slash' => ['https://example.test/', ''],
            'one segment' => ['https://example.test/archivum', '/archivum'],
            'one segment with a trailing slash' => ['https://example.test/archivum/', '/archivum'],
            'nested segments' => ['https://example.test/apps/archivum', '/apps/archivum'],
            'a port' => ['http://localhost:8080/archivum', '/archivum'],
        ];
    }

    /**
     * The font stylesheet is inlined into the page verbatim from the build, so
     * its `url(...)` values — relative to the stylesheet — end up being
     * resolved against the document instead. The preload links beside them go
     * through asset() and are correct, so the two disagree and the fonts
     * silently fall back to the system stack.
     */
    public function test_the_inlined_font_urls_point_at_the_built_assets()
    {
        $this->rebootWith(['APP_URL' => 'https://example.test/archivum']);

        $response = $this->get('http://app:8080/login');

        $response->assertSee('src: url("https://example.test/archivum/build/assets/', false);
        $response->assertDontSee('src: url("./', false);
        $response->assertDontSee('src: url("/build/', false);
    }

    public function test_the_inlined_font_urls_are_right_without_a_prefix_too()
    {
        $this->rebootWith(['APP_URL' => 'https://example.test']);

        $this->get('/login')
            ->assertSee('src: url("https://example.test/build/assets/', false);
    }

    #[DataProvider('assetUrls')]
    public function test_only_relative_asset_urls_are_resolved(string $css, string $expected)
    {
        $this->assertSame($expected, FontStyles::resolveAssetUrls($css));
    }

    /**
     * Whatever shape the build wrote — './name' under a relative base,
     * '/build/assets/name' under an absolute one — the file is the same file.
     * Anything already naming a host or carrying its own payload is left alone.
     *
     * @return array<string, array{string, string}>
     */
    public static function assetUrls(): array
    {
        $asset = 'http://localhost/build/assets/a.woff2';

        return [
            'relative to the stylesheet' => ['url("./a.woff2")', sprintf('url("%s")', $asset)],
            'root-relative' => ['url("/build/assets/a.woff2")', sprintf('url("%s")', $asset)],
            'unquoted' => ['url(./a.woff2)', sprintf('url(%s)', $asset)],
            'single quoted' => ["url('./a.woff2')", sprintf("url('%s')", $asset)],
            'an absolute url' => ['url("https://cdn.test/a.woff2")', 'url("https://cdn.test/a.woff2")'],
            'a data payload' => ['url("data:font/woff2;base64,AA")', 'url("data:font/woff2;base64,AA")'],
            'protocol-relative, which names a host' => ['url("//cdn.test/a.woff2")', 'url("//cdn.test/a.woff2")'],
        ];
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
