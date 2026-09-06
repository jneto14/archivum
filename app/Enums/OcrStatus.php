<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How far text extraction has got on a single attachment.
 *
 * The three terminal states are deliberately distinct: `Skipped` and
 * `Unavailable` both mean "no text", but only one of them is worth telling a
 * user to fix. `Failed` means the attempt itself broke, and is the only one
 * worth retrying.
 */
enum OcrStatus: string
{
    /** Queued, or waiting for the job to pick it up. */
    case Pending = 'pending';

    /** The job is running. */
    case Processing = 'processing';

    /** Text was extracted — possibly an empty string, if the page is blank. */
    case Completed = 'completed';

    /**
     * The page was read, but too little of it confidently enough to keep.
     *
     * Distinct from `Completed` with no text, which is a blank page: this one
     * had something on it that the engine could not read — handwriting, most
     * often. No text is stored, so nothing reaches the search index or the
     * duplicate fingerprint. Not worth retrying; the same file reads the same
     * way (ARC-118).
     */
    case PoorlyRead = 'poorly_read';

    /** Nothing to extract from: the file is neither a PDF nor an image. */
    case Skipped = 'skipped';

    /** Extraction is switched off, or the system binaries are not installed. */
    case Unavailable = 'unavailable';

    /** The attempt threw; `ocr_error` on the attachment says what. */
    case Failed = 'failed';
}
