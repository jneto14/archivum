<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Actions\Workspace\CalculateWorkspaceUsage;
use App\Models\Document;
use Illuminate\Support\Facades\Storage;

class DeleteDocument
{
    public function __construct(private readonly CalculateWorkspaceUsage $calculateUsage) {}

    /**
     * Delete a Document, purging its attachments' stored files from disk and
     * cascading its tags and location history.
     *
     * @param Document $document The document to delete.
     *
     * @return void No return value; the document and its attachment files are deleted as a side effect.
     */
    public function handle(Document $document): void
    {
        foreach ($document->attachments as $attachment) {
            Storage::disk($attachment->disk)->delete($attachment->path);
        }

        $document->delete();

        $this->calculateUsage->forget($document->workspace);
    }
}
