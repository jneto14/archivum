<?php

declare(strict_types=1);

use App\Console\Commands\LearnIntakeLabels;
use App\Console\Commands\PruneExpiredDocumentExports;
use App\Console\Commands\ResetDemo;
use App\Support\DemoMode;
use Illuminate\Support\Facades\Schedule;

Schedule::command(PruneExpiredDocumentExports::class)->daily();
Schedule::command('activitylog:clean')->daily();

/*
| A job that exhausts its retries leaves a row behind for good. The failure a
| user cares about is already on the `Task` row, which the Tasks page shows and
| offers a retry for, so `failed_jobs` is a debugging trail rather than a
| record worth keeping forever.
*/
Schedule::command('queue:prune-failed', ['--hours' => 336])->daily();

/*
| Weekly, because it reads every document a workspace has and the answer it
| gives moves at the speed of an archive being filled — a phrase that no three
| documents agree on today will not be agreed on by tomorrow either.
|
| Safe to leave running unattended: everything it writes is a question for a
| workspace admin, and nothing it finds is read by anything until one of them
| says yes.
*/
Schedule::command(LearnIntakeLabels::class)->weeklyOn(1, '03:00');

/*
| Registered only on a demo installation, so an ordinary one has nothing
| scheduled that could ever wipe it. The command refuses on its own account
| too — this is the outer of two independent guards, not the only one.
*/
if (DemoMode::enabled()) {
    Schedule::command(ResetDemo::class)
        ->dailyAt((string) config('archivum.demo.reset_at'));
}
