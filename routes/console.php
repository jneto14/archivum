<?php

declare(strict_types=1);

use App\Console\Commands\PruneExpiredDocumentExports;
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
