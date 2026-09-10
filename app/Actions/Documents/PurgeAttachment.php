<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Actions\Workspace\CalculateWorkspaceUsage;
use App\Models\DocumentAttachment;
use Illuminate\Support\Facades\Storage;

class PurgeAttachment
{
    public function __construct(private readonly CalculateWorkspaceUsage $calculateUsage) {}

    /**
     * Destroy an attachment for good, unlinking its stored file.
     *
     * @param DocumentAttachment $attachment The trashed attachment to destroy permanently.
     *
     * @return void No return value; the stored file and the record are destroyed as a side effect.
     */
    public function handle(DocumentAttachment $attachment): void
    {
        $document = $attachment->document()->withTrashed()->first();

        Storage::disk($attachment->disk)->delete($attachment->path);

        $attachment->forceDelete();

        // Only meaningful while the document itself is still around; a purge
        // reached by destroying the document has nothing left to re-index.
        if ($document !== null && $document->deleted_at === null) {
            $document->refreshOcrText();
            $this->calculateUsage->forget($document->workspace);
        }
    }
}
