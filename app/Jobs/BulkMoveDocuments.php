<?php

namespace App\Jobs;

use App\Actions\Organization\MigrateSchemeDocuments;
use App\Models\OrganizationScheme;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class BulkMoveDocuments implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly OrganizationScheme $source,
        public readonly OrganizationScheme $target,
    ) {}

    public function handle(MigrateSchemeDocuments $action): void
    {
        $action->handle($this->source, $this->target);
    }
}
