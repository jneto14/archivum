<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What somebody answered about a scan's reading, alongside the
 * `ocr_reviewed_at` timestamp that records when.
 *
 * The timestamp alone used to be the whole answer, because every way of
 * setting it involved a person looking at the page. Bulk review (ARC-127)
 * breaks that: after a re-extraction the queue holds thousands of readings and
 * the realistic action is to clear them, which is not the same claim as having
 * read one. Only `Confirmed` means somebody vouched for the text, and keeping
 * that distinguishable is what stops a bulk action from quietly asserting the
 * one thing ARC-118 exists to require.
 */
enum OcrReviewOutcome: string
{
    /** A person read what OCR made of the page and kept it. */
    case Confirmed = 'confirmed';

    /** A person read it, did not believe it, and the text was deleted. */
    case Rejected = 'rejected';

    /** A person saw a page the engine itself refused. There was no text to judge. */
    case Acknowledged = 'acknowledged';

    /** Taken off the queue without being read, one of many at once. */
    case Dismissed = 'dismissed';

    /**
     * Whether this outcome means a person actually vouched for the text.
     *
     * @return bool True only for a reading somebody read and kept.
     */
    public function isVouchedFor(): bool
    {
        return $this === self::Confirmed;
    }
}
