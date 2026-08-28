<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Organization\MigrateNodeDocuments;
use App\Models\OrganizationNode;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;

/**
 * Queues the migration of every document under one organization node to
 * another, off the request cycle since a node can hold a large number of
 * documents.
 */
class BulkMoveDocuments implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param OrganizationNode $source The node documents are currently located at.
     * @param OrganizationNode $target The node documents are moved onto.
     */
    public function __construct(
        public readonly OrganizationNode $source,
        public readonly OrganizationNode $target,
    ) {}

    /**
     * @param MigrateNodeDocuments $action Resolved from the container by the queue worker.
     *
     * @return void No return value; documents are relocated as a side effect.
     *
     * @throws InvalidArgumentException If the source and target node are the same, or belong to
     *                                  different workspaces (should not happen if dispatched via the
     *                                  controller, which validates this first — defense in depth).
     */
    public function handle(MigrateNodeDocuments $action): void
    {
        $action->handle($this->source, $this->target);
    }
}
