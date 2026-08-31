<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Tests\TestCase;

/**
 * Guards the committed icons against drifting from the mark they are drawn
 * from.
 *
 * The favicons are binary and nothing else in CI looks at them, so a change to
 * the mark's geometry or to the brand colour would otherwise leave the app
 * shipping the old icons indefinitely. If this fails, run
 * `php artisan brand:icons` and commit what it writes.
 */
class GenerateBrandIconsTest extends TestCase
{
    /**
     * The icons the command owns, all of them served straight out of public/.
     *
     * @var list<string>
     */
    private const array ICONS = ['favicon.svg', 'favicon.ico', 'apple-touch-icon.png'];

    public function test_the_committed_icons_match_the_current_brand_mark()
    {
        $directory = sys_get_temp_dir() . '/brand-icons-' . uniqid();
        mkdir($directory);

        try {
            $this->artisan('brand:icons', ['--path' => $directory])->assertExitCode(0);

            foreach (self::ICONS as $icon) {
                $this->assertFileEquals(
                    $directory . '/' . $icon,
                    public_path($icon),
                    "public/{$icon} is out of date — run `php artisan brand:icons` and commit the result.",
                );
            }
        } finally {
            foreach (self::ICONS as $icon) {
                @unlink($directory . '/' . $icon);
            }
            @rmdir($directory);
        }
    }

    public function test_the_favicon_holds_every_size_a_browser_asks_for()
    {
        $ico = (string) file_get_contents(public_path('favicon.ico'));

        // '8bit' keeps this a byte offset — see the note in GenerateBrandIcons.
        $count = unpack('v', mb_substr($ico, 4, 2, '8bit'))[1];
        $sizes = [];

        for ($index = 0; $index < $count; $index++) {
            $sizes[] = ord($ico[6 + 16 * $index]);
        }

        $this->assertSame([16, 32, 48], $sizes);
    }
}
