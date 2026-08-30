<?php

declare(strict_types=1);

use App\Console\Commands\PruneExpiredDocumentExports;
use Illuminate\Support\Facades\Schedule;

Schedule::command(PruneExpiredDocumentExports::class)->daily();
Schedule::command('activitylog:clean')->daily();
