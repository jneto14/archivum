<?php

declare(strict_types=1);

namespace App\Services\Ocr;

use App\Enums\OcrStatus;

/**
 * The outcome of running text extraction over one attachment.
 *
 * Carries the status as well as the text because "no text" has several
 * distinct causes that the interface has to tell apart: a spreadsheet nothing
 * can be extracted from, an installation without the binaries, and a blank
 * page that was read successfully.
 */
readonly class ExtractedText
{
    /**
     * The status is narrowed to the three outcomes extraction can actually
     * produce. `Pending` and `Processing` describe an attachment before a
     * result exists, and `Failed` is recorded from the exception rather than
     * returned, so none of them can ever arrive here.
     *
     * @param OcrStatus $status How the attempt ended.
     * @param string $text The extracted text; empty for every status other than Completed.
     */
    private function __construct(
        public OcrStatus $status,
        public string $text,
    ) {}

    /**
     * Text was extracted. An empty string is still a success — a blank scan
     * has no text, and re-running would not change that.
     *
     * @param string $text The extracted text.
     *
     * @return self A Completed result.
     */
    public static function completed(string $text): self
    {
        return new self(OcrStatus::Completed, mb_trim($text));
    }

    /**
     * The file holds nothing text can be extracted from — it is neither a PDF
     * nor an image.
     *
     * @return self A Skipped result.
     */
    public static function skipped(): self
    {
        return new self(OcrStatus::Skipped, '');
    }

    /**
     * Extraction could not run: it is switched off, or the system binaries are
     * missing on this installation.
     *
     * @return self An Unavailable result.
     */
    public static function unavailable(): self
    {
        return new self(OcrStatus::Unavailable, '');
    }
}
