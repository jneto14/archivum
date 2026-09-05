<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\SignedLink;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A signed link has to survive the journey the deployment puts it through.
 *
 * Under a path prefix it did not, and never had: URLs are generated from
 * `APP_URL`, so the signature covered
 * `https://example.test/archivum/capture/{id}` — while the proxy strips that
 * prefix before forwarding, leaving the application to rebuild and verify
 * `https://example.test/capture/{id}`. Two different strings, so the HMAC could
 * never match and every signed link came back 403.
 *
 * Nothing caught it because an installation served from the root has no prefix
 * to lose, and that is the only shape the suite had ever exercised.
 *
 * Asserted against a route defined here rather than the application's own, so
 * the mechanism can be checked in both deployment shapes without a database —
 * the environment is rewritten and the application rebooted, which a test
 * holding an open transaction cannot do. `CaptureUploadTest` and `TaskTest`
 * cover the real routes.
 */
class SignedLinksTest extends TestCase
{
    private const HOST = 'https://example.test';

    private const PREFIXED = self::HOST . '/archivum';

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

        Route::middleware('signed:relative')
            ->get('signed-probe', fn () => 'through')
            ->name('signed-probe');

        // A name given after the route is added does not reach the lookup the
        // generator reads, so `route('signed-probe')` would not find it.
        Route::getRoutes()->refreshNameLookups();
    }

    /**
     * Restores what phpunit.xml pins rather than unsetting: removing the
     * variable would take the suite-wide value with it, and every later test in
     * the process would boot without it.
     */
    protected function tearDown(): void
    {
        putenv('APP_URL=http://localhost');
        $_ENV['APP_URL'] = $_SERVER['APP_URL'] = 'http://localhost';

        parent::tearDown();
    }

    /** The link as the proxy forwards it: same host, prefix removed. */
    private function asForwarded(string $link): string
    {
        return self::HOST . Str::after($link, self::PREFIXED);
    }

    private function probeLink(): string
    {
        return SignedLink::temporary('signed-probe', now()->addDay());
    }

    /**
     * The whole failure, end to end: build the link, then request it the way the
     * proxy delivers it — same link, prefix removed.
     */
    public function test_a_signed_link_survives_the_proxy_stripping_the_prefix()
    {
        $this->rebootWith(['APP_URL' => self::PREFIXED]);

        $link = $this->probeLink();

        $this->assertStringStartsWith(self::PREFIXED . '/signed-probe?', $link);

        $this->get($this->asForwarded($link))->assertOk();
    }

    /**
     * The same link built from inside a request that has already had its prefix
     * stripped, which is where the QR code is actually generated.
     *
     * This is the case a relative signed route gets wrong on its own:
     * `RouteUrlGenerator` strips only what `$request->getBaseUrl()` reports, and
     * behind the proxy that is empty — so the prefix would stay in the signature
     * while the request being verified has none. Built from a queued job instead
     * there is no such request, the base URL comes from `APP_URL`, and the same
     * call would give a different answer.
     */
    public function test_a_link_built_inside_a_stripped_request_signs_the_same_path()
    {
        $this->rebootWith(['APP_URL' => self::PREFIXED]);

        $fromConsole = $this->probeLink();

        // Establishes a request whose base URL is empty, as the proxy leaves it.
        $this->get(self::HOST . '/login');

        $fromRequest = $this->probeLink();

        $this->assertSame(
            Str::before($fromConsole, '?expires='),
            Str::before($fromRequest, '?expires='),
        );

        $this->get($this->asForwarded($fromRequest))->assertOk();
    }

    /**
     * The same link on an installation served from the root, where there is no
     * prefix to lose. Both shapes have to work, or fixing one breaks the other.
     */
    public function test_a_signed_link_works_on_an_installation_served_from_the_root()
    {
        $this->rebootWith(['APP_URL' => self::HOST]);

        $link = $this->probeLink();

        $this->assertStringStartsWith(self::HOST . '/signed-probe?', $link);

        $this->get($link)->assertOk();
    }

    /**
     * Taking the host and the prefix out of the signature must not take the
     * signature with them.
     */
    public function test_a_tampered_link_is_still_refused()
    {
        $this->rebootWith(['APP_URL' => self::PREFIXED]);

        $link = $this->asForwarded($this->probeLink());

        $this->get($link . '&extra=1')->assertForbidden();
    }

    public function test_an_unsigned_request_reaches_nothing()
    {
        $this->rebootWith(['APP_URL' => self::PREFIXED]);

        $this->get(self::HOST . '/signed-probe')->assertForbidden();
    }
}
