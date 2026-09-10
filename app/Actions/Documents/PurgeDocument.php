<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Actions\Workspace\CalculateWorkspaceUsage;
use App\Models\Document;
use Illuminate\Support\Facades\Storage;

class PurgeDocument
{
    public function __construct(private readonly CalculateWorkspaceUsage $calculateUsage) {}

    /**
     * Destroy a Document for good: its attachments' files leave the disk, and
     * the row and everything cascading from it leave the database.
     *
     * This is the irreversible one, and the only path that unlinks a file. It
     * is reached from emptying the trash, from purging one item out of it, and
     * from the scheduled prune once the retention window has passed.
     *
     * Attachment rows are removed by the foreign key's cascade rather than
     * here, but their *files* are not — nothing in the database knows about
     * the disk — so they are unlinked first. `withTrashed()` because by
     * definition this runs on a document whose attachments are trashed too,
     * and the relation would otherwise return none of them and orphan every
     * file.
     *
     * @param Document $document The trashed document to destroy permanently.
     *
     * @return void No return value; the files and records are destroyed as a side effect.
     */
    public function handle(Document $document): void
    {
        $workspace = $document->workspace;

        foreach ($document->attachments()->withTrashed()->get() as $attachment) {
            Storage::disk($attachment->disk)->delete($attachment->path);
        }

        $document->forceDelete();

        $this->calculateUsage->forget($workspace);
    }
}
