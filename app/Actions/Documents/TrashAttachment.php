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

        // The document's searchable text is a concatenation of its attachments'
        // extracted text, and `attachments()` no longer returns this one, so
        // rebuilding drops its words. Without it the document stays findable by
        // words that only ever appeared on a scan somebody removed — and the
        // trash would quietly keep them in the index.
        $document->refreshOcrText();

        $this->calculateUsage->forget($document->workspace);
    }
}
