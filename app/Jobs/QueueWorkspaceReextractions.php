<?php

declare(strict_types=1);

namespace App\Jobs;

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
 * Fills a bulk re-extraction's batch with one `ExtractAttachmentTexts` per
 * run of matching attachments.
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
     * How many attachment ids are read out of the database per round trip.
     * Large enough that a ten-thousand-attachment archive is a score of
     * queries rather than ten thousand, and a whole multiple of every
     * sensible extraction chunk, so none of them comes out ragged.
     */
    private const READ = 500;

    /**
     * @param Task $task The bulk task standing for this sweep on the Tasks page.
     * @param ReextractionFilter $filter Which of the workspace's attachments are re-read.
     * @param int|null $limit Stop after this many attachments, or null for all of them.
     */
    public function __construct(
        public readonly Task $task,
        public readonly ReextractionFilter $filter,
        public readonly ?int $limit = null,
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
        $size = max(1, (int) config('archivum.ocr.bulk_chunk'));
        $task = $this->task;
        $queued = 0;

        $this->filter->apply($workspace)
            ->select(['id'])
            ->chunkById(self::READ, function (Collection $attachments) use ($batch, $limit, $size, $task, &$queued): bool {
                if ($batch->cancelled()) {
                    return false;
                }

                // The cap is applied to what came back rather than asked of
                // the query: `chunkById` sets a limit of its own, so a
                // `limit()` on the builder is silently overwritten.
                if ($limit !== null) {
                    $attachments = $attachments->take($limit - $queued);
                }

                // One job per run of attachments, not per attachment: the
                // queue round trip costs more than reading a text-layer PDF.
                //
                // No `onQueue()` here: the batch carries it, and `add()`
                // would override a per-job one anyway.
                $jobs = [];

                foreach (array_chunk($attachments->pluck('id')->all(), $size) as $ids) {
                    $jobs[] = new ExtractAttachmentTexts(array_map(strval(...), $ids), $task);
                }

                $batch->add($jobs);

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
