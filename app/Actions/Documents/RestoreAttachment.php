<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Actions\Workspace\CalculateWorkspaceUsage;
use App\Models\DocumentAttachment;

class RestoreAttachment
{
    public function __construct(private readonly CalculateWorkspaceUsage $calculateUsage) {}

    /**
     * Bring an attachment back out of the trash.
     *
     * @param DocumentAttachment $attachment The trashed attachment to restore.
     *
     * @return void No return value; the attachment is restored and the document re-indexed, as a side effect.
     */
    public function handle(DocumentAttachment $attachment): void
    {
        $attachment->restore();

        $attachment->document->refreshOcrText();

        $this->calculateUsage->forget($attachment->document->workspace);
    }
}
