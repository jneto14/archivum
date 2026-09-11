<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Models\DocumentAttachment;
use App\Models\DocumentAttachmentVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RestoreAttachmentVersion
{
    public function __construct(private readonly QueueAttachmentTextExtraction $queueExtraction) {}

    /**
     * Put an earlier file back in the attachment's place, and push the one it
     * displaces into the history behind it.
     *
     * A swap rather than a fresh upload: the restored file keeps the row it
     * already had on disk, and the version it displaces takes its place in the
     * history. Copying the bytes instead would charge the workspace twice for
     * a file it already holds, and leave two rows pointing at one path for the
     * purge to get wrong.
     *
     * So the chain is not a stack of numbered revisions — it is the set of
     * files this attachment is not currently holding, ordered by when each
     * stopped being current. Restoring the same version twice running is a
     * no-op the second time, because after the first it is the current file
     * and no longer in the history at all.
     *
     * The restored file's text was thrown away when it was superseded, so it
     * is read again like any other arriving file rather than being recovered.
     *
     * @param DocumentAttachmentVersion $version The superseded file to make current again.
     * @param User $user The user asking for it.
     *
     * @return DocumentAttachment The attachment, now holding $version's file.
     */
    public function handle(DocumentAttachmentVersion $version, User $user): DocumentAttachment
    {
        $attachment = $version->attachment;
        $document = $attachment->document;

        DB::transaction(function () use ($attachment, $version): void {
            $attachment->replaceFileWith([
                'disk' => $version->disk,
                'path' => $version->path,
                'filename' => $version->filename,
                'mime_type' => $version->mime_type,
                'size' => $version->size,
                'checksum' => $version->checksum,
            ], $version->uploaded_by, $version->uploaded_at);

            // The file is on the attachment now; leaving the row here would
            // have the history claim a file it is also holding, and the purge
            // unlink it twice.
            $version->delete();
        });

        // Same reasoning as a replacement: the reading described the file that
        // has just moved into the history, and the document's mirror must stop
        // quoting it now rather than when the next reading lands.
        $attachment->markOcrProcessing();
        $document->refreshOcrText();

        $this->queueExtraction->handle($attachment, $user);

        return $attachment;
    }
}
