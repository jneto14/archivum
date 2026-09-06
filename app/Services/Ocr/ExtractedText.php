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
     * The status is narrowed to the four outcomes extraction can actually
     * produce. `Pending` and `Processing` describe an attachment before a
     * result exists, and `Failed` is recorded from the exception rather than
     * returned, so none of them can ever arrive here.
     *
     * @param OcrStatus $status How the attempt ended.
     * @param string $text The extracted text; empty for every status other than Completed.
     * @param int|null $wordCount Words the engine returned, or null where nothing was recognised.
     * @param int|null $confidentWordCount Words it was sure enough of to keep.
     */
    private function __construct(
        public OcrStatus $status,
        public string $text,
        public ?int $wordCount = null,
        public ?int $confidentWordCount = null,
    ) {}

    /**
     * Text was extracted. An empty string is still a success — a blank scan
     * has no text, and re-running would not change that.
     *
     * @param string $text The extracted text.
     * @param int|null $wordCount Words the engine returned, or null where the text came from a source that does not score itself.
     * @param int|null $confidentWordCount Words it was sure enough of to keep.
     *
     * @return self A Completed result.
     */
    public static function completed(string $text, ?int $wordCount = null, ?int $confidentWordCount = null): self
    {
        return new self(OcrStatus::Completed, mb_trim($text), $wordCount, $confidentWordCount);
    }

    /**
     * The page was read, but too little of it survived the confidence floor
     * to be worth storing.
     *
     * No text is carried, deliberately. Keeping the fragment that scored well
     * would put it in the search index and the duplicate fingerprint, where a
     * handful of words the engine half-guessed at does more harm than the
     * absence of them (ARC-118).
     *
     * @return self A PoorlyRead result.
     */
    public static function poorlyRead(?int $wordCount = null, ?int $confidentWordCount = null): self
    {
        return new self(OcrStatus::PoorlyRead, '', $wordCount, $confidentWordCount);
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
