<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Actions\Demo\WipeDemoStorage;
use App\Models\User;
use App\Support\DemoMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `demo:reset` deletes every record and every uploaded file.
 *
 * The refusal is what these tests are for, more than the reset is. A demo that
 * quietly fails to reset is an inconvenience; a real installation that resets
 * is somebody's archive gone. So every way the guard can be reached is pinned
 * here, including the one that motivated the second lock: a working `.env`
 * copied from the demo onto another host.
 *
 * The happy path is covered in halves — the guard clearing, and the files being
 * deleted — rather than by running the command through. Letting the suite
 * execute `migrate:fresh` would drop the tables the rest of the run depends on.
 */
class ResetDemoCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.url', 'https://demo.archivum.test');
    }

    public function test_it_refuses_when_demo_mode_is_off()
    {
        config()->set('archivum.demo.enabled', false);
        config()->set('archivum.demo.reset_confirm', 'https://demo.archivum.test');

        $this->artisan('demo:reset')
            ->expectsOutputToContain('DEMO_MODE is not enabled')
            ->assertExitCode(1);
    }

    public function test_it_refuses_when_the_confirmation_is_not_set()
    {
        config()->set('archivum.demo.enabled', true);
        config()->set('archivum.demo.reset_confirm', null);

        $this->artisan('demo:reset')
            ->expectsOutputToContain('DEMO_RESET_CONFIRM is not set')
            ->assertExitCode(1);
    }

    /**
     * The accident the second lock exists for: someone starts a real
     * installation from the demo's working `.env`, `DEMO_MODE=true` survives
     * the copy, and the only thing standing between that customer's archive
     * and a nightly wipe is that the confirmation still names the old host.
     */
    public function test_it_refuses_when_the_confirmation_names_another_installation()
    {
        config()->set('archivum.demo.enabled', true);
        config()->set('archivum.demo.reset_confirm', 'https://demo.archivum.test');
        config()->set('app.url', 'https://arquivo.cliente.test');

        $this->artisan('demo:reset')
            ->expectsOutputToContain('does not match APP_URL')
            ->assertExitCode(1);
    }

    public function test_a_refusal_destroys_nothing()
    {
        config()->set('archivum.demo.enabled', false);

        Storage::fake('local');
        Storage::disk('local')->put('documents/abc/scan.pdf', 'a real attachment');

        $user = User::factory()->create();

        $this->artisan('demo:reset')->assertExitCode(1);

        Storage::disk('local')->assertExists('documents/abc/scan.pdf');
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_the_guard_clears_when_both_locks_are_satisfied()
    {
        config()->set('archivum.demo.enabled', true);
        config()->set('archivum.demo.reset_confirm', 'https://demo.archivum.test');

        $this->assertNull(DemoMode::resetBlockedReason());
    }

    /**
     * A trailing slash or a capital letter is the same installation. Failing
     * the lock for that reason would teach whoever hit it to stop believing
     * the lock, which is worse than the inconvenience.
     */
    public function test_the_confirmation_ignores_trailing_slashes_and_case()
    {
        config()->set('archivum.demo.enabled', true);
        config()->set('archivum.demo.reset_confirm', 'https://DEMO.archivum.test/');

        $this->assertNull(DemoMode::resetBlockedReason());
    }

    /**
     * Attachments live on a disk, not in the database, so truncating tables
     * alone would leave every uploaded file orphaned on the volume forever.
     */
    public function test_it_deletes_attachments_and_exports_from_the_disk()
    {
        Storage::fake('local');
        $disk = Storage::disk('local');

        $disk->put('documents/one/scan.pdf', 'scan');
        $disk->put('documents/two/photo.jpg', 'photo');
        $disk->put('exports/workspace/report.csv', 'csv');

        $cleared = app(WipeDemoStorage::class)->handle();

        $disk->assertMissing('documents/one/scan.pdf');
        $disk->assertMissing('documents/two/photo.jpg');
        $disk->assertMissing('exports/workspace/report.csv');
        $this->assertSame(['documents', 'exports'], $cleared);
    }

    public function test_wiping_storage_is_safe_when_nothing_has_been_uploaded()
    {
        Storage::fake('local');

        $this->assertSame([], app(WipeDemoStorage::class)->handle());
    }
}
