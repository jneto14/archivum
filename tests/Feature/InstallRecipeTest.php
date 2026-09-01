<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Guards the `.env` the README tells a self-hoster to write.
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

    public function test_the_readme_recipe_pins_every_setting_with_a_misleading_default()
    {
        $recipe = $this->installRecipe();

        foreach (array_keys(self::REQUIRED) as $key) {
            $this->assertMatchesRegularExpression(
                '/^' . preg_quote($key, '/') . '=/m',
                $recipe,
                "The README's install recipe does not set {$key}. Its framework default is "
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
     * The heredoc from the README's Installing section.
     *
     * @return string The body of the `cat > .env` block.
     */
    private function installRecipe(): string
    {
        $readme = (string) file_get_contents(base_path('README.md'));

        $this->assertSame(
            1,
            preg_match('/^cat > \.env <<EOF$(.*?)^EOF$/ms', $readme, $matches),
            'The README should contain exactly one `cat > .env <<EOF` install block.',
        );

        return $matches[1];
    }
}
