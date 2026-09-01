<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ExtractAttachmentText;
use App\Models\DocumentAttachment;
use App\Models\Task;
use Tests\TestCase;

/**
 * Guards the ordering of the three timeouts that bound a queued job.
 *
 * They have to hold this order:
 *
 *     queue retry_after  >  worker --timeout  >=  job $timeout  >=  OCR worst case
 *
 * When `retry_after` drops below the job's timeout the queue decides a job that
 * is still running has been lost and hands it to a second worker, so the work
 * happens twice — for text extraction that means two workers rasterizing the
 * same scan and racing to write the same attachment. It is invisible in
 * development, where nothing runs long enough to trip it, and it was the state
 * of this app before ARC-50: `retry_after` was Laravel's stock 90 seconds
 * against a job that may legitimately run for the better part of an hour.
 *
 * The numbers are derived from `archivum.ocr` rather than written down, but the
 * derivation is repeated in `config/queue.php` because one config file cannot
 * read another. This test is what stops the two copies drifting.
 */
class QueueTimeoutTest extends TestCase
{
    public function test_the_job_timeout_covers_the_slowest_possible_extraction()
    {
        $worstCase = (int) config('archivum.ocr.max_pages') * (int) config('archivum.ocr.timeout');

        $this->assertGreaterThanOrEqual(
            $worstCase,
            (int) config('archivum.ocr.job_timeout'),
            'Every page of a scan may take the full per-binary timeout, so a job timeout below '
            . 'max_pages * timeout kills extractions that were still making progress.',
        );
    }

    public function test_the_queue_gives_a_job_longer_to_finish_than_the_job_itself_takes()
    {
        $this->assertGreaterThan(
            (int) config('archivum.ocr.job_timeout'),
            (int) config('queue.connections.database.retry_after'),
            'retry_after must outlast the longest job, or the queue re-dispatches work that is still running.',
        );
    }

    public function test_the_extraction_job_takes_its_timeout_from_the_ocr_config()
    {
        $job = new ExtractAttachmentText(new DocumentAttachment(), new Task());

        $this->assertSame((int) config('archivum.ocr.job_timeout'), $job->timeout);
    }
}
