<?php

declare(strict_types=1);

namespace App\Services\Ocr;

/**
 * Reads Tesseract's TSV output into text, keeping only the words it was sure
 * of.
 *
 * Separate from `TesseractEngine`, which is about running a binary. This is
 * about what its table means, and it is the half with decisions in it — both
 * of the ones below were made wrong first and cost a real page (ARC-118).
 *
 * ## Line structure is rebuilt, not dropped
 *
 * `SuggestDocumentMetadata` finds a value by the words in front of it, working
 * along a line. Text reassembled as one long run of words would join the end of
 * one line to the start of the next and invent labels that were never written.
 *
 * ## A dropped word leaves a wider gap behind it
 *
 * This is why the filter cannot be a `filter()` over a list of words. Removing
 * a word closes the space it occupied, and the value reader decides where a
 * value ends by adjacency: groups separated by exactly one space are one value.
 *
 * Dropping the em dash out of `3 — 49051 242344062 1165797` — it scored 29 on a
 * real scan — handed that reader four unrelated numbers as a single tax number.
 *
 * Two spaces says what actually happened: something was here and could not be
 * read. It costs nothing anywhere else, because every other consumer of this
 * text either collapses whitespace or splits on it, and it restores the
 * boundary the unreadable word was providing.
 *
 * A gap at the start of a line needs no marker; the line break is already a
 * stronger separator than any run can cross.
 *
 * ## Lines are counted as well as words
 *
 * Tesseract lays out blocks, paragraphs and lines before it recognises
 * anything, so a page of handwriting comes back with lines on it and not one
 * readable word, while a blank sheet comes back with no layout at all. Counting
 * only words makes those two the same answer, and a photographed page of
 * handwriting was duly recorded as a blank page that had been read perfectly.
 */
class TesseractTsv
{
    /**
     * Columns in Tesseract's TSV output, which carries no names of its own
     * past the header row.
     */
    private const COLUMN_LEVEL = 0;

    private const COLUMN_PAGE = 1;

    private const COLUMN_BLOCK = 2;

    private const COLUMN_PARAGRAPH = 3;

    private const COLUMN_LINE = 4;

    private const COLUMN_CONFIDENCE = 10;

    private const COLUMN_TEXT = 11;

    private const COLUMN_COUNT = 12;

    /** The `level` of a row describing one word. Coarser rows describe the blocks and lines around it. */
    private const LEVEL_WORD = 5;

    /**
     * The `level` of a row describing one line of writing.
     *
     * Counted as well as the words, because layout happens before recognition:
     * a page of handwriting comes back with lines on it and not one readable
     * word, while a genuinely blank sheet comes back with no layout at all.
     * Without this the two are the same answer — no words — and a photographed
     * page of handwriting was recorded as a blank page read perfectly.
     */
    private const LEVEL_LINE = 4;

    /**
     * @param int $minWordConfidence Confidence, 0-100, a word must carry to be kept.
     */
    public function __construct(private readonly int $minWordConfidence) {}

    /**
     * @param string $output The TSV Tesseract wrote, header row included.
     *
     * @return RecognizedText The surviving text, and the word counts behind it.
     */
    public function read(string $output): RecognizedText
    {
        /** @var list<string> $lines Lines finished so far. */
        $lines = [];
        $currentKey = null;
        $currentLine = '';
        $afterDroppedWord = false;
        $wordCount = 0;
        $confidentWordCount = 0;
        $lineCount = 0;

        foreach (explode("\n", $output) as $row) {
            $columns = explode("\t", mb_rtrim($row, "\r"));

            if (count($columns) < self::COLUMN_COUNT) {
                continue;
            }

            $level = (int) $columns[self::COLUMN_LEVEL];

            if ($level === self::LEVEL_LINE) {
                $lineCount++;

                continue;
            }

            if ($level !== self::LEVEL_WORD) {
                continue;
            }

            $word = mb_trim($columns[self::COLUMN_TEXT]);

            // Tesseract emits word rows carrying no text; they are not words it
            // read badly, they are spacing, and counting them would drag every
            // page's ratio down by however many it happened to emit.
            if ($word === '') {
                continue;
            }

            $wordCount++;

            if ((float) $columns[self::COLUMN_CONFIDENCE] < $this->minWordConfidence) {
                $afterDroppedWord = true;

                continue;
            }

            $confidentWordCount++;

            $key = implode('/', [
                $columns[self::COLUMN_PAGE],
                $columns[self::COLUMN_BLOCK],
                $columns[self::COLUMN_PARAGRAPH],
                $columns[self::COLUMN_LINE],
            ]);

            if ($key !== $currentKey) {
                if ($currentKey !== null) {
                    $lines[] = $currentLine;
                }

                $currentKey = $key;
                $currentLine = '';
                $afterDroppedWord = false;
            }

            $currentLine .= match (true) {
                $currentLine === '' => $word,
                $afterDroppedWord => '  ' . $word,
                default => ' ' . $word,
            };

            $afterDroppedWord = false;
        }

        if ($currentKey !== null) {
            $lines[] = $currentLine;
        }

        return new RecognizedText(mb_trim(implode("\n", $lines)), $wordCount, $confidentWordCount, $lineCount);
    }
}
