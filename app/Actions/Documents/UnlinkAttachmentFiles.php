<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Models\DocumentAttachment;
use Illuminate\Support\Facades\Storage;

class UnlinkAttachmentFiles
{
    /**
     * Unlink every file an attachment holds or has held.
     *
     * An attachment is a chain, not a file: the one it is holding now, and
     * every version something replaced. The rows go with it through the
     * foreign key's cascade, but nothing in the database knows about the disk,
     * so destroying an attachment without coming through here leaves its
     * superseded scans behind as files no row points at and nothing will ever
     * clean up (ARC-124).
     *
     * Reads `versions` off the relation, so a caller walking many attachments
     * can eager-load it and spare itself a query each.
     *
     * @param DocumentAttachment $attachment The attachment whose files are unlinked.
     *
     * @return void No return value; the files leave the disk as a side effect.
     */
    public function handle(DocumentAttachment $attachment): void
    {
        Storage::disk($attachment->disk)->delete($attachment->path);

        foreach ($attachment->versions as $version) {
            Storage::disk($version->disk)->delete($version->path);
        }
    }
}
