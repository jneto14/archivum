<?php

namespace App\Jobs;

use App\Actions\Organization\MigrateSchemeDocuments;
use App\Models\OrganizationScheme;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queues the migration of every document under one organization scheme to
 * another, off the request cycle since a scheme can hold a large number of
 * documents.
 */
class BulkMoveDocuments implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  OrganizationScheme  $source  The scheme documents are currently organized under.
     * @param  OrganizationScheme  $target  The scheme documents are moved into.
     */
    public function __construct(
        public readonly OrganizationScheme $source,
        public readonly OrganizationScheme $target,
    ) {}

    /**
     * @param  MigrateSchemeDocuments  $action  Resolved from the container by the queue worker.
     *
     * @throws \InvalidArgumentException If the source and target scheme are the same, or belong to
     *                                   different workspaces (should not happen if dispatched via the
     *                                   controller, which validates this first — defense in depth).
     */
    public function handle(MigrateSchemeDocuments $action): void
    {
        $action->handle($this->source, $this->target);
    }
}
