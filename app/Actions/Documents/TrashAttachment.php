<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Actions\Workspace\CalculateWorkspaceUsage;
use App\Models\DocumentAttachment;

class TrashAttachment
{
    public function __construct(private readonly CalculateWorkspaceUsage $calculateUsage) {}

    /**
     * Move an attachment to the workspace's trash, leaving its stored file
     * where it is.
     *
     * @param DocumentAttachment $attachment The attachment to move to the trash.
     *
     * @return void No return value; the attachment is trashed and the document re-indexed, as a side effect.
     */
    public function handle(DocumentAttachment $attachment): void
    {
        $document = $attachment->document;

        $attachment->delete();

        // The column's `nullOnDelete` only fires on a real delete, which
        // trashing is not, so it is cleared here by hand.
        DocumentAttachment::withTrashed()
            ->where('duplicate_of_attachment_id', $attachment->id)
            ->update(['duplicate_of_attachment_id' => null]);

        $document->refreshOcrText();

        $this->calculateUsage->forget($document->workspace);
    }
}
