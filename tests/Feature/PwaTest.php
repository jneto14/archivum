<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Vite;
use Tests\TestCase;

/**
 * What has to hold for a browser to offer to install this installation.
 *
 * None of it announces itself when it breaks: a manifest served with the wrong
 * media type, behind auth, or naming a scope the app is not under simply means
 * the install option never appears, with nothing in the console to say why.
 */
class PwaTest extends TestCase
{
    /**
     * Fetched before anyone signs in, so it must not be behind `auth` — a
     * manifest that redirects to the login page is read as HTML and quietly
     * ignored.
     */
    public function test_the_manifest_describes_an_installable_app_to_a_visitor_who_is_not_signed_in()
    {
        $response = $this->get(route('pwa.manifest'));

        $response->assertOk();
        $this->assertStringStartsWith(
            'application/manifest+json',
            (string) $response->headers->get('Content-Type'),
        );

        $response->assertJsonPath('display', 'standalone')
            ->assertJsonPath('name', config('app.name'))
            ->assertJsonPath('start_url', url('/') . '/')
            ->assertJsonPath('scope', url('/') . '/');
    }

    /**
     * Chrome refuses to install without an icon of at least 192px, and Android
     * crops every icon to the launcher's shape — a maskable one says which part
     * of the artwork may be eaten, instead of the launcher guessing.
     */
    public function test_the_manifest_offers_the_icon_sizes_and_shapes_an_install_needs()
    {
        $icons = collect($this->get(route('pwa.manifest'))->json('icons'));

        $this->assertEqualsCanonicalizing(
            ['192x192', '512x512', '512x512'],
            $icons->pluck('sizes')->all(),
        );
        $this->assertContains('maskable', $icons->pluck('purpose')->all());

        foreach ($icons as $icon) {
            $this->assertSame('image/png', $icon['type']);
            $this->assertFileExists(public_path(basename((string) $icon['src'])));
        }
    }

    /**
     * A worker may only claim the directory it was served from, so this one has
     * to come from the root of the installation. Registering it from anywhere
     * deeper would leave most of the app uncovered.
     */
    public function test_the_service_worker_is_served_as_javascript_from_the_root()
    {
        $this->assertSame('sw.js', (string) app('router')->getRoutes()->getByName('pwa.service-worker')?->uri());

        $response = $this->get(route('pwa.service-worker'));

        $response->assertOk();
        $this->assertStringStartsWith(
            'text/javascript',
            (string) $response->headers->get('Content-Type'),
        );
        $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
    }

    /**
     * The offline page travels inside the worker rather than being fetched, so
     * it is there in the one situation it exists for.
     */
    public function test_the_service_worker_carries_the_offline_page_it_will_show()
    {
        $this->get(route('pwa.service-worker'))
            ->assertSee(__('pwa.offline_title'), false)
            ->assertSee(__('pwa.offline_retry'), false);
    }

    /**
     * The acceptance criterion this whole design turns on: a deploy has to be
     * picked up rather than served from the previous build's cache. The asset
     * manifest's hash is the cache name, so a new build is a different worker
     * and the old cache is dropped when it activates.
     */
    public function test_a_new_asset_build_gives_the_worker_a_new_cache_to_fill()
    {
        $this->partialMock(Vite::class)
            ->shouldReceive('manifestHash')
            ->andReturn('11111111111111111111111111111111');

        $this->get(route('pwa.service-worker'))
            ->assertSee('const VERSION = "11111111111111111111111111111111"', false)
            ->assertSee('archivum-assets-${VERSION}', false);

        $this->refreshApplication();

        $this->partialMock(Vite::class)
            ->shouldReceive('manifestHash')
            ->andReturn('22222222222222222222222222222222');

        $this->get(route('pwa.service-worker'))
            ->assertDontSee('11111111111111111111111111111111', false)
            ->assertSee('const VERSION = "22222222222222222222222222222222"', false);
    }

    /**
     * The page has to point at the manifest for any of it to be read at all.
     */
    public function test_the_page_links_the_manifest_and_names_a_theme_colour()
    {
        $this->get(route('login'))
            ->assertSee('<link rel="manifest" href="' . route('pwa.manifest') . '">', false)
            ->assertSee('name="theme-color"', false)
            ->assertSee('name="apple-mobile-web-app-title"', false);
    }
}
