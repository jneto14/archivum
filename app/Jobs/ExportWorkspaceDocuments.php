<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Document;
use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Exports every document in the task's workspace to a CSV file, tracking
 * progress on the Task row and releasing the export concurrency lock the
 * dispatching action acquired, whether the export succeeds or fails.
 */
class ExportWorkspaceDocuments implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param Task $task The task record tracking this export.
     * @param string $lockOwner The owner token of the Cache::lock() acquired before this job was dispatched.
     */
    public function __construct(
        public readonly Task $task,
        public readonly string $lockOwner,
    ) {}

    /**
     * @return void No return value; the Task row and a CSV file on disk are updated as a side effect.
     */
    public function handle(): void
    {
        $this->task->markProcessing();

        try {
            $workspace = $this->task->workspace;
            $disk = config('archivum.attachments.disk');
            $path = "exports/{$workspace->id}/{$this->task->id}.csv";

            $documents = Document::query()
                ->where('workspace_id', $workspace->id)
                ->with('documentType')
                ->orderBy('created_at')
                ->get();

            Storage::disk($disk)->put($path, $this->toCsv($documents));

            $this->task->markCompleted([
                'disk' => $disk,
                'path' => $path,
                'documents_count' => $documents->count(),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            $this->task->markFailed($exception->getMessage());
        } finally {
            Cache::restoreLock($this->task->type->lockKey($this->task->workspace_id), $this->lockOwner)->release();
        }
    }

    /**
     * @param Collection<int, Document> $documents The documents to include, in order.
     *
     * @return string The documents rendered as CSV text.
     */
    private function toCsv(Collection $documents): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new RuntimeException('Unable to open a temporary stream to build the export CSV.');
        }

        fputcsv($handle, ['Title', 'Document type', 'Document date', 'Created at']);

        foreach ($documents as $document) {
            fputcsv($handle, [
                $document->title,
                $document->documentType->name,
                $document->document_date?->toDateString(),
                $document->created_at?->toDateTimeString(),
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
