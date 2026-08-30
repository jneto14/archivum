<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Task;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes generated document-export CSV files once they're past the
 * configured retention window, so exports don't accumulate on disk
 * indefinitely. The Task rows themselves are left in place as history;
 * only the underlying file is removed, so a later download attempt
 * (in-app or via the emailed signed link) reports 404 instead of erroring.
 */
#[Signature('exports:prune')]
#[Description('Delete document export CSV files past their retention window')]
class PruneExpiredDocumentExports extends Command
{
    /**
     * @return int The command's exit code.
     */
    public function handle(): int
    {
        $retentionDays = config('archivum.attachments.export_retention_days');
        $cutoff = now()->subDays($retentionDays);
        $pruned = 0;

        Task::query()
            ->where('type', TaskType::DocumentExport)
            ->where('status', TaskStatus::Completed)
            ->where('finished_at', '<=', $cutoff)
            ->whereNotNull('result')
            ->chunkById(100, function (Collection $tasks) use (&$pruned) {
                foreach ($tasks as $task) {
                    $disk = $task->result['disk'] ?? null;
                    $path = $task->result['path'] ?? null;

                    if ($disk === null || $path === null) {
                        continue;
                    }

                    if (Storage::disk($disk)->exists($path)) {
                        Storage::disk($disk)->delete($path);
                        $pruned++;
                    }
                }
            });

        $this->info("Pruned {$pruned} expired document export(s).");

        return self::SUCCESS;
    }
}
