<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Actions\Workspace\CalculateWorkspaceUsage;
use App\Models\Document;
use Illuminate\Support\Facades\DB;

class RestoreDocument
{
    public function __construct(private readonly CalculateWorkspaceUsage $calculateUsage) {}

    /**
     * Bring a Document back out of the trash, along with the attachments that
     * were trashed with it.
     *
     * Only the attachments flagged `trashed_with_document` come back — the set
     * `TrashDocument` marked as it cascaded. Anything deleted separately
     * beforehand carries no flag and stays in the trash, where its owner put
     * it. Restoring by "every trashed attachment of this document" would
     * resurrect a scan somebody had thrown away weeks earlier, and restoring
     * by a matching `deleted_at` did exactly that whenever the two deletions
     * fell in the same second.
     *
     * @param Document $document The trashed document to restore.
     *
     * @return void No return value; the document and its cascaded attachments are restored as a side effect.
     */
    public function handle(Document $document): void
    {
        DB::transaction(function () use ($document): void {
            $document->attachments()
                ->onlyTrashed()
                ->where('trashed_with_document', true)
                ->update(['deleted_at' => null, 'trashed_with_document' => false]);

            $document->restore();
        });

        // The attachments that just came back carry text the document's
        // searchable mirror was rebuilt without.
        $document->refreshOcrText();

        $this->calculateUsage->forget($document->workspace);
    }
}
