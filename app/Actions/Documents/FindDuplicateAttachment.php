<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Models\DocumentAttachment;
use App\Services\Ocr\TextFingerprint;
use Illuminate\Database\Eloquent\Builder;

/**
 * Finds the attachment a freshly extracted one appears to be a copy of.
 *
 * Fingerprints cannot be compared in SQL — the question is "within N bits",
 * not "equal" — so the candidates are streamed out of the database and
 * compared in PHP. That is affordable because a fingerprint is one integer:
 * even a workspace with tens of thousands of attachments is a few hundred
 * kilobytes and a few million XORs, on the queue, once per upload.
 */
class FindDuplicateAttachment
{
    public function __construct(private readonly TextFingerprint $fingerprints) {}

    /**
     * Look for an existing attachment whose text is close enough to $attachment's
     * to be the same document.
     *
     * Only other documents are considered. Two attachments of one document are
     * routinely near-identical — the front and back of a form, a page scanned
     * twice into the same record — and telling the user their document
     * duplicates itself is noise, not a warning.
     *
     * @param DocumentAttachment $attachment The attachment just fingerprinted, which sets the workspace to search and the document to exclude.
     * @param int $simhash Its fingerprint, passed in rather than read back off the model so the search can run before it is persisted.
     *
     * @return DocumentAttachment|null The closest match within the configured distance, oldest first on a tie, or null if there is none.
     */
    public function handle(DocumentAttachment $attachment, int $simhash): ?DocumentAttachment
    {
        $workspaceId = $attachment->document?->workspace_id;

        if ($workspaceId === null) {
            return null;
        }

        $maxDistance = (int) config('archivum.intake.duplicate_max_distance');

        $closest = null;
        $closestDistance = $maxDistance + 1;

        $candidates = DocumentAttachment::query()
            ->select(['id', 'document_id', 'filename', 'text_simhash'])
            ->whereNotNull('text_simhash')
            ->where('document_id', '!=', $attachment->document_id)
            ->whereHas('document', fn (Builder $query) => $query->where('workspace_id', $workspaceId))
            // Walked in id order, which is a UUIDv7 and so chronological: the
            // first match at a given distance is the earliest filed copy, which
            // is the one worth pointing at.
            ->lazyById(500);

        foreach ($candidates as $candidate) {
            $distance = $this->fingerprints->distance($simhash, (int) $candidate->text_simhash);

            if ($distance < $closestDistance) {
                $closest = $candidate;
                $closestDistance = $distance;
            }

            // Identical text; nothing later can beat it.
            if ($closestDistance === 0) {
                break;
            }
        }

        return $closest;
    }
}
