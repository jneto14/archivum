<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Identity is the one area `ci:check` cannot see, so what is asserted here is
 * narrow on purpose: that nothing Laravel authored still ships, and that the
 * pieces a link preview needs are actually present in the response.
 *
 * How it *looks* is not testable and is not attempted — see `.ai/rules/pages.md`.
 */
class BrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_shell_carries_a_complete_link_preview_card()
    {
        $response = $this->get(route('login'));

        $response->assertOk();

        foreach ([
            '<meta property="og:type" content="website">',
            '<meta property="og:title" content="Archivum">',
            '<meta property="og:site_name" content="Archivum">',
            '<meta property="og:image:width" content="1200">',
            '<meta property="og:image:height" content="630">',
            '<meta name="twitter:card" content="summary_large_image">',
        ] as $tag) {
            $response->assertSee($tag, false);
        }

        // Absolute, because a relative path in an og:image is ignored by every
        // crawler that reads it.
        $response->assertSee('<meta property="og:image" content="' . url('/og-image.png') . '">', false);
        $response->assertSee('<meta name="twitter:image" content="' . url('/og-image.png') . '">', false);
    }

    public function test_the_preview_description_follows_the_requested_locale()
    {
        $this->get(route('login'))->assertSee(mb_trim(__('meta.description', [], 'en')), false);

        $user = User::factory()->create(['locale' => 'pt']);

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertSee(mb_trim(__('meta.description', [], 'pt')), false);
    }

    public function test_the_preview_image_and_icons_exist_and_are_not_laravels()
    {
        foreach (['og-image.png', 'favicon.ico', 'favicon.svg', 'apple-touch-icon.png', 'email-logo.png', 'logo.svg'] as $asset) {
            $this->assertFileExists(public_path($asset), "Missing branding asset [{$asset}].");
        }

        foreach (['favicon.svg', 'logo.svg'] as $svg) {
            $this->assertStringContainsString(
                'Archivum',
                (string) file_get_contents(public_path($svg)),
                "[{$svg}] should carry the Archivum mark, not an unlabelled one.",
            );
        }
    }

    public function test_no_laravel_asset_is_left_in_the_shell_or_the_mail_template()
    {
        $this->get(route('login'))->assertDontSee('laravel.com', false);

        $header = (string) file_get_contents(
            resource_path('views/vendor/mail/html/header.blade.php'),
        );

        // The published header shipped a hard-coded `<img>` pointing at
        // laravel.com, behind a check on the app name being "Laravel".
        $this->assertStringNotContainsString('laravel.com', $header);
        $this->assertStringContainsString('email-logo.png', $header);
    }

    public function test_the_app_name_falls_back_to_archivum_rather_than_laravel()
    {
        $this->assertSame('Archivum', config('app.name'));

        foreach (['config/app.php', 'resources/views/app.blade.php'] as $file) {
            $this->assertStringNotContainsString(
                "'Laravel'",
                (string) file_get_contents(base_path($file)),
                "[{$file}] still falls back to Laravel's name.",
            );
        }
    }
}
