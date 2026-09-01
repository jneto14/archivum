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

    /** Nothing to extract from: the file is neither a PDF nor an image. */
    case Skipped = 'skipped';

    /** Extraction is switched off, or the system binaries are not installed. */
    case Unavailable = 'unavailable';

    /** The attempt threw; `ocr_error` on the attachment says what. */
    case Failed = 'failed';
}
