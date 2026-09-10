<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\DocumentAttachment;
use App\Models\Task;
use App\Support\ReextractionFilter;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use LogicException;

/**
 * Fills a bulk re-extraction's batch with one `ExtractAttachmentText` per
 * matching attachment.
 *
 * A loader job rather than a batch built at dispatch time, which is the
 * pattern the framework documents for batching thousands of jobs — and here it
 * is also what makes the batch safe. A batch dispatched with its first chunk
 * and topped up afterwards can finish that chunk before the second arrives, at
 * which point its completion callback fires over a sweep that has barely
 * started. This job is itself in the batch, so the batch cannot be complete
 * while there is still something to add (ARC-122).
 */
class QueueWorkspaceReextractions implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * How many extractions are added to the batch per round trip. Large
     * enough that a ten-thousand-attachment archive is a score of writes
     * rather than ten thousand, small enough that no chunk holds an
     * unreasonable number of hydrated models at once.
     */
    private const CHUNK = 500;

    /**
     * @param Task $task The bulk task standing for this sweep on the Tasks page.
     * @param ReextractionFilter $filter Which of the workspace's attachments are re-read.
     * @param int|null $limit Stop after this many attachments, or null for all of them.
     * @param string|null $extractionQueue The queue the extractions are pushed onto, so a sweep cannot outrank an upload.
     */
    public function __construct(
        public readonly Task $task,
        public readonly ReextractionFilter $filter,
        public readonly ?int $limit = null,
        public readonly ?string $extractionQueue = null,
    ) {}

    /**
     * @return void No return value; adds extraction jobs to this job's batch and moves the task into "processing" as a side effect.
     */
    public function handle(): void
    {
        $this->task->markProcessing();

        $batch = $this->batch() ?? throw new LogicException(
            'QueueWorkspaceReextractions must run inside the batch it fills.',
        );

        $workspace = $this->task->workspace;
        $limit = $this->limit;
        $queued = 0;

        $this->filter->apply($workspace)
            ->select(['id', 'document_id'])
            ->chunkById(self::CHUNK, function (Collection $attachments) use ($batch, $limit, &$queued): bool {
                if ($batch->cancelled()) {
                    return false;
                }

                // The cap is applied to what came back rather than asked of
                // the query: `chunkById` sets a limit of its own, so a
                // `limit()` on the builder is silently overwritten.
                if ($limit !== null) {
                    $attachments = $attachments->take($limit - $queued);
                }

                $batch->add($attachments->map(
                    fn (DocumentAttachment $attachment) => (new ExtractAttachmentText($attachment))
                        ->onQueue($this->extractionQueue),
                )->all());

                $queued += $attachments->count();

                return $limit === null || $queued < $limit;
            });

        // Recorded on the payload rather than the result: `Task::markFailed()`
        // replaces the result wholesale, and a sweep that fell over is exactly
        // when knowing how much of it had been queued matters.
        $this->task->update([
            'payload' => ['queued' => $queued] + ($this->task->payload ?? []),
        ]);
    }
}
