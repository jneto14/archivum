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
 * kilobytes and a few million XORs.
 *
 * The candidates are deliberately **not** hydrated as models. That used to be
 * harmless because this ran once per upload, against an archive that grew one
 * file at a time. Bulk re-extraction (ARC-122) runs it once per attachment
 * over the whole archive, which makes it quadratic — and measured on 5,000
 * attachments, building 3,654 Eloquent models per call was 194ms of a 210ms
 * extraction, against 9ms for reading the file. The comparison was never the
 * cost; hydrating was. Only the winner becomes a model, at the end.
 */
class FindDuplicateAttachment
{
    /**
     * How many fingerprints are fetched per round trip.
     *
     * Large on purpose. Every chunk re-runs the whole query, subquery on
     * `documents` included, so the round trips are what this costs and not the
     * rows: over 3,667 candidates, chunks of 500 took 103ms and chunks of this
     * size 20ms, which is as fast as fetching the lot in one go. Still chunked
     * rather than `get()` so the memory has a ceiling on an archive far larger
     * than the one this was measured on.
     */
    private const CHUNK = 10000;

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

        $closestId = null;
        $closestDistance = $maxDistance + 1;

        $candidates = DocumentAttachment::query()
            ->select(['document_attachments.id', 'text_simhash'])
            ->whereNotNull('text_simhash')
            ->where('document_id', '!=', $attachment->document_id)
            ->whereHas('document', fn (Builder $query) => $query->where('workspace_id', $workspaceId))
            // `toBase()` keeps the model's global scopes — the trashed
            // attachments and the attachments of trashed documents stay out —
            // and drops only the hydration, which is what this loop costs.
            ->toBase()
            // Walked in id order, which is a UUIDv7 and so chronological: the
            // first match at a given distance is the earliest filed copy, which
            // is the one worth pointing at.
            ->lazyById(self::CHUNK, 'document_attachments.id', 'id');

        foreach ($candidates as $candidate) {
            $distance = $this->fingerprints->distance($simhash, (int) $candidate->text_simhash);

            if ($distance < $closestDistance) {
                $closestId = (string) $candidate->id;
                $closestDistance = $distance;
            }

            // Identical text; nothing later can beat it.
            if ($closestDistance === 0) {
                break;
            }
        }

        // One query, and only when there is something to return: the caller
        // wants the model, but the search does not.
        return $closestId === null
            ? null
            : DocumentAttachment::query()->where('id', $closestId)->first();
    }
}
