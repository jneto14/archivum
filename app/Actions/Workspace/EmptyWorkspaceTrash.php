<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Actions\Documents\PurgeAttachment;
use App\Actions\Documents\PurgeDocument;
use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class EmptyWorkspaceTrash
{
    public function __construct(
        private readonly PurgeDocument $purgeDocument,
        private readonly PurgeAttachment $purgeAttachment,
        private readonly CalculateWorkspaceUsage $calculateUsage,
    ) {}

    /**
     * Destroy everything in a workspace's trash, optionally only what has been
     * there since before a cutoff.
     *
     * Documents go first, because purging one takes its attachments with it
     * through the foreign key's cascade — doing attachments first would mean
     * walking rows that a later document purge was about to remove anyway.
     * What remains afterwards is the attachments trashed on their own, whose
     * documents are still live.
     *
     * Chunked rather than loaded whole: emptying a large archive's trash is
     * the one call here that can touch tens of thousands of rows, and it also
     * unlinks a file per attachment.
     *
     * @param Workspace $workspace The workspace whose trash is emptied.
     * @param CarbonInterface|null $trashedBefore Only purge items trashed before this moment; everything when null.
     *
     * @return array{documents: int, attachments: int} How many of each were destroyed.
     */
    public function handle(Workspace $workspace, ?CarbonInterface $trashedBefore = null): array
    {
        $purgedDocuments = 0;
        $purgedAttachments = 0;

        Document::onlyTrashed()
            ->where('workspace_id', $workspace->id)
            ->when(
                $trashedBefore !== null,
                fn (Builder $query): Builder => $query->where('deleted_at', '<=', $trashedBefore),
            )
            ->chunkById(100, function (Collection $documents) use (&$purgedDocuments): void {
                foreach ($documents as $document) {
                    $this->purgeDocument->handle($document);
                    $purgedDocuments++;
                }
            });

        DocumentAttachment::onlyTrashed()
            ->whereHas('document', fn (Builder $query): Builder => $query->where('workspace_id', $workspace->id))
            ->when(
                $trashedBefore !== null,
                fn (Builder $query): Builder => $query->where('deleted_at', '<=', $trashedBefore),
            )
            ->chunkById(100, function (Collection $attachments) use (&$purgedAttachments): void {
                foreach ($attachments as $attachment) {
                    $this->purgeAttachment->handle($attachment);
                    $purgedAttachments++;
                }
            });

        $this->calculateUsage->forget($workspace);

        return ['documents' => $purgedDocuments, 'attachments' => $purgedAttachments];
    }
}
