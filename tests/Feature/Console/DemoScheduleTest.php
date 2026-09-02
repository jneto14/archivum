<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * The reset is registered on the schedule only where it belongs.
 *
 * The command guards itself, but a scheduled entry that exists at all on an
 * ordinary installation is one configuration mistake away from running. Nothing
 * is scheduled unless DEMO_MODE is on, so there is nothing to misfire.
 *
 * DEMO_MODE is set through the environment rather than through `config()`
 * because `routes/console.php` is read while the application boots, long before
 * a test body runs.
 */
class DemoScheduleTest extends TestCase
{
    /** @var list<string> */
    private array $commands = [];

    /**
     * Restore the suite-wide default rather than unsetting it. Removing the
     * variable entirely takes phpunit.xml's own `DEMO_MODE=false` with it, and
     * every later test in the process then boots with it simply absent — which
     * is how turning demo mode on in a developer's .env managed to fail
     * unrelated tests in Settings.
     */
    protected function tearDown(): void
    {
        $this->setDemoMode(false);

        parent::tearDown();
    }

    private function setDemoMode(bool $enabled): void
    {
        $value = $enabled ? 'true' : 'false';

        putenv("DEMO_MODE={$value}");
        $_ENV['DEMO_MODE'] = $_SERVER['DEMO_MODE'] = $value;
    }

    public function test_an_ordinary_installation_has_nothing_scheduled_that_could_wipe_it()
    {
        $this->bootWithDemoMode(false);

        $this->assertNotContains('demo:reset', $this->commands);
    }

    public function test_a_demo_installation_resets_daily_at_the_configured_hour()
    {
        $this->bootWithDemoMode(true);

        $this->assertContains('demo:reset', $this->commands);

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'demo:reset'));

        // 04:00 is the configured default: minute 0 of hour 4, every day.
        $this->assertSame('0 4 * * *', $event->expression);
    }

    /**
     * Rebuild the application with DEMO_MODE set, so the console routes are
     * read under the value being tested.
     */
    private function bootWithDemoMode(bool $enabled): void
    {
        $this->setDemoMode($enabled);

        $this->refreshApplication();

        $this->commands = collect(app(Schedule::class)->events())
            ->map(fn ($event): string => (string) $event->command)
            ->map(fn (string $command): string => str_contains($command, 'demo:reset') ? 'demo:reset' : $command)
            ->all();
    }
}
