<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Guards the `.env` the install documentation tells a self-hoster to write.
 *
 * Some framework defaults are not merely different from what this stack needs,
 * they are silently wrong: `DB_CONNECTION` falls back to `sqlite`, so a stack
 * missing it starts happily and then reports that a database file called
 * "archivum" does not exist; `SCOUT_DRIVER` falls back to `collection`, which
 * filters in PHP and never consults the full-text index the app maintains.
 *
 * Both were left out when the recipe was rewritten by hand, and both got as far
 * as a running container before failing. This asserts the block still names
 * every setting whose absence would be that kind of wrong.
 */
class InstallRecipeTest extends TestCase
{
    /**
     * Settings the recipe must pin, and the framework default that makes each
     * one dangerous to omit.
     *
     * @var array<string, string>
     */
    private const REQUIRED = [
        'DB_CONNECTION' => 'sqlite',
        'SCOUT_DRIVER' => 'collection',
        'CACHE_STORE' => 'database',
        'SESSION_DRIVER' => 'database',
        'QUEUE_CONNECTION' => 'database',
        'DB_HOST' => '127.0.0.1',
        'REDIS_HOST' => '127.0.0.1',
        'APP_KEY' => '',
    ];

    public function test_the_documented_recipe_pins_every_setting_with_a_misleading_default()
    {
        $recipe = $this->installRecipe();

        foreach (array_keys(self::REQUIRED) as $key) {
            $this->assertMatchesRegularExpression(
                '/^' . preg_quote($key, '/') . '=/m',
                $recipe,
                "The install recipe in docs/deployment.md does not set {$key}. Its framework default is "
                . "'" . self::REQUIRED[$key] . "', which produces a stack that starts and then misbehaves.",
            );
        }
    }

    public function test_the_recipe_does_not_connect_as_root()
    {
        $this->assertDoesNotMatchRegularExpression(
            '/^DB_USERNAME=root$/m',
            $this->installRecipe(),
            'MySQL refuses to start with MYSQL_USER=root, and says so only in its own logs.',
        );
    }

    /**
     * The heredoc from the Installing section of `docs/deployment.md`.
     *
     * The recipe moved out of the README when the specification was split into
     * `docs/`. This test follows it rather than being deleted: the defaults it
     * guards are still the ones that produce a stack which starts and then
     * misbehaves.
     *
     * @return string The body of the `cat > .env` block.
     */
    /**
     * The demo recipe has its own settings whose absence is silently wrong, and
     * they are worse than the install recipe's: an installation missing them is
     * not broken, it is an ordinary one that happens to be public. No banner
     * warning visitors their work is temporary, no credentials on the login
     * screen, nothing ever reset, and none of the restrictions that stop the
     * first visitor leaving it useless to the next.
     */
    public function test_the_documented_demo_recipe_turns_demo_mode_on()
    {
        $recipe = $this->demoRecipe();

        foreach (['DEMO_MODE=true', 'DEMO_RESET_CONFIRM=', 'DEMO_EMAIL=', 'DEMO_PASSWORD='] as $setting) {
            $this->assertStringContainsString(
                $setting,
                $recipe,
                "The demo recipe in docs/deployment.md does not set {$setting}.",
            );
        }
    }

    /**
     * `demo:reset` refuses unless DEMO_RESET_CONFIRM repeats the installation's
     * own APP_URL, so a recipe where the two disagree documents a demo that
     * quietly never resets.
     */
    public function test_the_demo_recipe_confirms_its_own_app_url()
    {
        $recipe = $this->demoRecipe();

        preg_match('/^APP_URL=(.*)$/m', $recipe, $appUrl);
        preg_match('/^DEMO_RESET_CONFIRM=(.*)$/m', $recipe, $confirm);

        $this->assertSame(
            mb_rtrim($appUrl[1] ?? 'a', '/'),
            mb_rtrim($confirm[1] ?? 'b', '/'),
            'DEMO_RESET_CONFIRM must repeat APP_URL, or demo:reset refuses to run.',
        );
    }

    /**
     * The demo dataset comes from `demo:reset`. Seeding the ordinary way leaves
     * a demo with nothing in it until the small hours, and then destroys
     * whatever account was made in the meantime.
     */
    public function test_the_demo_recipe_seeds_with_demo_reset()
    {
        $path = base_path('docs/deployment.md');
        $documentation = (string) file_get_contents($path);

        $this->assertStringContainsString(
            'artisan demo:reset',
            mb_substr($documentation, (int) mb_strpos($documentation, '### A public demo')),
            'The demo recipe should seed with demo:reset rather than db:seed.',
        );
    }

    /**
     * The dotenv block from the "A public demo" section of
     * `docs/deployment.md`.
     *
     * @return string The body of that block.
     */
    private function demoRecipe(): string
    {
        $path = base_path('docs/deployment.md');

        $this->assertFileExists($path);

        $documentation = (string) file_get_contents($path);
        $section = mb_strstr($documentation, '### A public demo');

        $this->assertIsString($section, 'docs/deployment.md should document a public demo recipe.');

        $this->assertSame(
            1,
            preg_match('/```dotenv$(.*?)^```/ms', (string) $section, $matches),
            'The public demo section should open with a dotenv block.',
        );

        return $matches[1];
    }

    private function installRecipe(): string
    {
        $path = base_path('docs/deployment.md');

        $this->assertFileExists($path, 'The deployment documentation is where the install recipe lives.');

        $this->assertSame(
            1,
            preg_match('/^cat > \.env <<EOF$(.*?)^EOF$/ms', (string) file_get_contents($path), $matches),
            'docs/deployment.md should contain exactly one `cat > .env <<EOF` install block.',
        );

        return $matches[1];
    }
}
