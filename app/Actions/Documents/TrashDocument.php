<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Actions\Workspace\CalculateWorkspaceUsage;
use App\Models\Document;
use Illuminate\Support\Facades\DB;

class TrashDocument
{
    public function __construct(private readonly CalculateWorkspaceUsage $calculateUsage) {}

    /**
     * Move a Document to the workspace's trash.
     *
     * Nothing is destroyed and nothing leaves the disk: the row stays, the
     * attachments' files stay where they are, and `PurgeDocument` is the only
     * thing that removes either. Deleting a document takes its metadata, its
     * tags and its location history with it, and the history is the part that
     * cannot be reconstructed from the paper — which is why a misclick in a
     * list view is not allowed to be the end of it.
     *
     * The document's attachments are trashed alongside it and flagged
     * `trashed_with_document`, which is what `RestoreDocument` brings back. An
     * attachment somebody had already deleted on its own is not flagged and
     * stays in the trash when the document returns. The flag exists rather
     * than a comparison of `deleted_at` values because that column has
     * one-second resolution: deleting an attachment and then its document in
     * the same second made the two indistinguishable, and the restore
     * resurrected a scan that had been thrown away.
     *
     * @param Document $document The document to move to the trash.
     *
     * @return void No return value; the document and its attachments are trashed as a side effect.
     */
    public function handle(Document $document): void
    {
        DB::transaction(function () use ($document): void {
            $document->delete();

            // `attachments()` excludes the already-trashed, so this flags and
            // stamps exactly the set this deletion is taking with it.
            $document->attachments()->update([
                'deleted_at' => $document->deleted_at,
                'trashed_with_document' => true,
            ]);
        });

        $this->calculateUsage->forget($document->workspace);
    }
}
