<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Demo\WipeDemoStorage;
use App\Support\DemoMode;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Returns a demo installation to its seeded state: every record dropped, every
 * uploaded file deleted, everything reseeded from {@see DemoSeeder}.
 *
 * This command exists to destroy data, so the guard matters more than the
 * feature. It refuses unless {@see DemoMode::resetBlockedReason()} clears both
 * locks, and it refuses *here* rather than in the scheduler: `php artisan
 * demo:reset` typed by hand on a production box has to be as safe as the
 * scheduled run, and a guard that lives in the schedule protects only the
 * schedule.
 *
 * The refusal is loud and exits non-zero, so a misconfigured demo shows up as
 * a failing scheduled task instead of quietly never resetting.
 */
#[Signature('demo:reset')]
#[Description('Wipe a demo installation back to its seeded dataset')]
class ResetDemo extends Command
{
    /**
     * @return int The command's exit code: 0 on a completed reset, 1 when refused.
     */
    public function handle(WipeDemoStorage $wipeStorage): int
    {
        $blocked = DemoMode::resetBlockedReason();

        if ($blocked !== null) {
            $this->error('Refusing to reset: ' . $blocked);
            $this->error('This command deletes every record and every uploaded file. It only runs on an installation that is explicitly a demo.');

            return self::FAILURE;
        }

        // Files first. If the run dies between the two halves, orphaned rows
        // pointing at deleted files are repaired by the next reset, whereas
        // files orphaned by a wiped database are invisible and accumulate on
        // the volume until it fills.
        foreach ($wipeStorage->handle() as $directory) {
            $this->line("Cleared {$directory}");
        }

        $this->wipeDatabase();

        $this->info('Demo reset complete.');

        return self::SUCCESS;
    }

    /**
     * Drop every table and reseed from the demo dataset.
     *
     * `migrate:fresh` is right here precisely because it is indiscriminate: a
     * demo holds nothing worth preserving, and it takes queued and failed jobs
     * with it, so a job dispatched before the reset cannot wake up and run
     * against records that no longer exist. Database-backed sessions go the
     * same way, which logs everyone out — correct for a demo, since a session
     * pointing at a deleted workspace is worse than a login screen.
     */
    private function wipeDatabase(): void
    {
        // A demo runs with APP_ENV=production, and AppServiceProvider prohibits
        // destructive migrations there — correct for every other installation,
        // and it would stop this command dead. Lifted only after both locks in
        // handle() have already cleared, so the protection is spent on a run
        // that has proven it is allowed to destroy data.
        DB::prohibitDestructiveCommands(false);

        Artisan::call('migrate:fresh', [
            '--force' => true,
            '--seed' => true,
            '--seeder' => 'Database\\Seeders\\DemoSeeder',
        ], $this->output);
    }
}
