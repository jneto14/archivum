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

        if ($limits !== null && $limits->exceedsStorage($this->calculateUsage->storageBytes($workspace), (int) $file->getSize())) {
            throw ValidationException::withMessages([
                'file' => __('document.storage_limit_exceeded'),
            ]);
        }

        $disk = (string) config('archivum.attachments.disk');
        $path = $file->store("documents/{$document->id}", $disk);
        $checksum = hash_file('sha256', $file->getRealPath());

        if ($path === false || $checksum === false) {
            throw new RuntimeException("Could not store the replacement for attachment {$attachment->id}.");
        }

        DB::transaction(fn () => $attachment->replaceFileWith([
            'disk' => $disk,
            'path' => $path,
            'filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size' => (int) $file->getSize(),
            'checksum' => $checksum,
        ], $uploader->id));

        $attachment->markOcrProcessing();
        $document->refreshOcrText();

        $this->calculateUsage->forget($workspace);

        $this->queueExtraction->handle($attachment, $uploader);

        return $attachment;
    }
}
