<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Actions\Workspace\CalculateWorkspaceUsage;
use App\Models\DocumentAttachment;
use Illuminate\Support\Facades\Storage;

class DeleteAttachment
{
    public function __construct(private readonly CalculateWorkspaceUsage $calculateUsage) {}

    /**
     * Delete an attachment and its underlying stored file.
     *
     * @param DocumentAttachment $attachment The attachment to remove, including its stored file on disk.
     *
     * @return void No return value; the stored file and record are deleted, and the document is re-indexed, as a side effect.
     */
    public function handle(DocumentAttachment $attachment): void
    {
        $document = $attachment->document;

        Storage::disk($attachment->disk)->delete($attachment->path);

        $attachment->delete();

        // The document's searchable text is a concatenation of its attachments'
        // extracted text, so deleting a scan has to rebuild it — otherwise the
        // document stays findable by words that only appeared on a file the
        // user just removed.
        $document->refreshOcrText();

        $this->calculateUsage->forget($document->workspace);
    }
}
