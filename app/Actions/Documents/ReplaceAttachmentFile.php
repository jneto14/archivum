<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Actions\Workspace\CalculateWorkspaceUsage;
use App\Models\DocumentAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ReplaceAttachmentFile
{
    public function __construct(
        private readonly CalculateWorkspaceUsage $calculateUsage,
        private readonly QueueAttachmentTextExtraction $queueExtraction,
    ) {}

    /**
     * Put a better scan in an attachment's place, keeping the one it replaces.
     *
     * The alternative people reach for — upload the new file, delete the old —
     * is two operations that are not atomic, produces two unrelated rows with
     * nothing saying which supersedes which, and throws away the only copy of
     * a page that the replacement may yet turn out to be worse than (ARC-124).
     *
     * @param DocumentAttachment $attachment The attachment whose file is replaced.
     * @param UploadedFile $file The file taking its place.
     * @param User $uploader The user recorded as having uploaded it.
     *
     * @return DocumentAttachment The attachment, now holding $file.
     *
     * @throws ValidationException If storing $file would take the workspace past its storage limit.
     * @throws RuntimeException If the file could not be written to the disk, or could not be read back to checksum.
     */
    public function handle(DocumentAttachment $attachment, UploadedFile $file, User $uploader): DocumentAttachment
    {
        $document = $attachment->document;
        $workspace = $document->workspace;
        $limits = $workspace->limits;

        // The storage limit applies and the attachment limit does not, which
        // is the same answer the trash got in ARC-123 and for the same reason:
        // the superseded file still occupies the disk it is charged against,
        // while the archive holds exactly as many attachments as it did
        // before — one — and a count that said otherwise would contradict the
        // list the user is looking at.
        //
        // The file being replaced is not credited back. It stays on disk, so
        // the replacement genuinely costs its own size on top.
        if ($limits !== null && $limits->exceedsStorage($this->calculateUsage->storageBytes($workspace), (int) $file->getSize())) {
            throw ValidationException::withMessages([
                'file' => __('document.storage_limit_exceeded'),
            ]);
        }

        $disk = (string) config('archivum.attachments.disk');
        $path = $file->store("documents/{$document->id}", $disk);
        $checksum = hash_file('sha256', $file->getRealPath());

        // A replacement that cannot be stored must not be recorded. The
        // attachment still holds a file that works, and half-applying this
        // would point it at a path with nothing behind it while pushing the
        // only good copy into a history nobody thinks to look in.
        if ($path === false || $checksum === false) {
            throw new RuntimeException("Could not store the replacement for attachment {$attachment->id}.");
        }

        DB::transaction(fn () => $attachment->replaceFileWith([
            'disk' => $disk,
            'path' => $path,
            'filename' => $file->getClientOriginalName(),
            // Stated rather than left null: `mime_type` is what decides
            // whether the browser may render the file inline, and a null
            // there is not in `INLINE_SAFE_TYPES` but is also not a type
            // anything downstream can reason about.
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size' => (int) $file->getSize(),
            'checksum' => $checksum,
        ], $uploader->id));

        // The old reading described a file that is now in the history, so it
        // goes before anything can read it again — and the document's mirror
        // is rebuilt from what is left immediately rather than when the new
        // reading lands. Waiting would leave the archive findable by a scan
        // nobody can open any more, and on an installation with extraction
        // switched off it would leave it that way for good.
        $attachment->markOcrProcessing();
        $document->refreshOcrText();

        $this->calculateUsage->forget($workspace);

        $this->queueExtraction->handle($attachment, $uploader);

        return $attachment;
    }
}
