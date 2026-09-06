<?php

declare(strict_types=1);

namespace App\Services\Ocr;

/**
 * What an OCR engine made of one or more images: the text worth keeping, and
 * how much of what it read it was sure about.
 *
 * The counts are the point. A string alone cannot say whether it is a clean
 * reading of a sparse page or the confident tenth of a page the engine mostly
 * guessed at, and those two want opposite treatment — the first is text, the
 * second is noise that would go into the search index and the duplicate
 * fingerprint as if it were text (ARC-118).
 */
readonly class RecognizedText
{
    /**
     * @param string $text The words that met the confidence floor, with the page's line structure kept.
     * @param int $wordCount Words the engine returned, whether or not they were kept.
     * @param int $confidentWordCount Words that met the floor, so the length of `text` in words.
     * @param int $lineCount Lines of writing the engine laid out, whether or not it could read them.
     */
    public function __construct(
        public string $text,
        public int $wordCount,
        public int $confidentWordCount,
        public int $lineCount = 0,
    ) {}

    /**
     * A reading of nothing: a blank page, or an engine that returned no words.
     *
     * @return self An empty result.
     */
    public static function empty(): self
    {
        return new self('', 0, 0, 0);
    }

    /**
     * A reading in which every word is trusted.
     *
     * For a source that carries no confidence of its own — an engine that does
     * not score its output, or a text layer read straight out of a PDF, which
     * is exact rather than recognised. Reporting such a reading as unsure
     * would send it to the page floor and throw away text nothing was ever
     * uncertain about.
     *
     * @param string $text The text, taken at face value.
     *
     * @return self A result whose every word counts as confident.
     */
    public static function confident(string $text): self
    {
        $words = preg_split('/\s+/', mb_trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $lines = preg_split('/\R/', mb_trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return new self(mb_trim($text), count($words), count($words), count($lines));
    }

    /**
     * Combine the readings of several pages into one.
     *
     * The counts add up across pages rather than being averaged per page,
     * because the question they answer is about the document: one bad page in
     * a twenty-page scan should not condemn the other nineteen, and one good
     * page should not rescue a scan that is otherwise unreadable.
     *
     * @param list<self> $pages The pages to join, in order.
     *
     * @return self One result covering all of them, pages separated by a blank line.
     */
    public static function join(array $pages): self
    {
        $texts = array_filter(
            array_map(static fn (self $page): string => $page->text, $pages),
            static fn (string $text): bool => $text !== '',
        );

        return new self(
            mb_trim(implode("\n\n", $texts)),
            array_sum(array_map(static fn (self $page): int => $page->wordCount, $pages)),
            array_sum(array_map(static fn (self $page): int => $page->confidentWordCount, $pages)),
            array_sum(array_map(static fn (self $page): int => $page->lineCount, $pages)),
        );
    }

    /**
     * The share of what was read that survived the confidence floor.
     *
     * When the engine returned no words at all, the count cannot answer this
     * and the layout has to. Tesseract lays out blocks, paragraphs and lines
     * before it recognises anything, so a page of handwriting comes back with
     * lines on it and not one readable word — while a genuinely blank sheet
     * comes back with no layout at all.
     *
     * Reading both as 1.0 was the bug that made this distinction necessary: a
     * photographed page of handwriting was recorded as a blank page that had
     * been read perfectly, which is the exact case the confidence filter
     * exists for (ARC-118).
     *
     * @return float Between 0.0 and 1.0.
     */
    public function confidentRatio(): float
    {
        if ($this->wordCount === 0) {
            return $this->lineCount === 0 ? 1.0 : 0.0;
        }

        return $this->confidentWordCount / $this->wordCount;
    }
}
