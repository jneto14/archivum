<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ocr\TesseractTsv;
use PHPUnit\Framework\TestCase;

/**
 * Driven with hand-built TSV rather than through the binary, because the cases
 * worth pinning are about a specific word scoring specifically badly, and
 * Tesseract cannot be talked into that on demand — a cleanly rendered em dash
 * scores 92, while the one on the scan that caused this scored 29.
 */
class TesseractTsvTest extends TestCase
{
    /**
     * One TSV word row.
     *
     * @param int $line The line number within the paragraph.
     * @param float $confidence The word's score, 0-100.
     * @param string $text The word itself.
     *
     * @return string A `level 5` row, tab separated.
     */
    private function word(int $line, float $confidence, string $text): string
    {
        return implode("\t", [5, 1, 1, 1, $line, 1, 0, 0, 10, 10, $confidence, $text]);
    }

    /**
     * @param list<string> $rows The word rows to wrap.
     *
     * @return string The rows behind a header, as Tesseract writes them.
     */
    private function tsv(array $rows): string
    {
        return implode("\n", [
            "level\tpage_num\tblock_num\tpar_num\tline_num\tword_num\tleft\ttop\twidth\theight\tconf\ttext",
            ...$rows,
        ]);
    }

    public function test_it_keeps_the_words_it_was_sure_of_and_counts_the_rest()
    {
        $read = (new TesseractTsv(60))->read($this->tsv([
            $this->word(1, 96.0, 'Factura'),
            $this->word(1, 22.0, 'xxx'),
            $this->word(1, 91.0, '2026'),
        ]));

        $this->assertSame(3, $read->wordCount);
        $this->assertSame(2, $read->confidentWordCount);
        $this->assertStringContainsString('Factura', $read->text);
        $this->assertStringNotContainsString('xxx', $read->text);
    }

    // The regression this class was pulled out for. On a real scan the line read
    // `3 — 49051 242344062 1165797`; the em dash scored 29 and was dropped,
    // which closed the space it held. SuggestDocumentMetadata decides where a
    // value ends by adjacency — groups one space apart are one value — so it
    // offered four unrelated numbers as a single tax number (ARC-118).
    public function test_a_dropped_word_leaves_a_wider_gap_instead_of_closing_it()
    {
        $read = (new TesseractTsv(60))->read($this->tsv([
            $this->word(1, 66.32, '3'),
            $this->word(1, 29.43, '—'),
            $this->word(1, 91.49, '49051'),
            $this->word(1, 96.11, '242344062'),
            $this->word(1, 86.32, '1165797'),
        ]));

        $this->assertSame('3  49051 242344062 1165797', $read->text);
        $this->assertStringNotContainsString(
            '3 49051',
            $read->text,
            'One space between them is what makes the value reader treat them as one number.',
        );
    }

    public function test_consecutive_dropped_words_still_leave_one_gap()
    {
        $read = (new TesseractTsv(60))->read($this->tsv([
            $this->word(1, 95.0, 'total'),
            $this->word(1, 10.0, '~'),
            $this->word(1, 12.0, '~'),
            $this->word(1, 95.0, '98.80'),
        ]));

        $this->assertSame('total  98.80', $read->text);
    }

    // A line break already separates more strongly than any run can cross, so a
    // gap at the start of a line would be a margin, not information.
    public function test_a_gap_at_the_start_of_a_line_leaves_no_marker()
    {
        $read = (new TesseractTsv(60))->read($this->tsv([
            $this->word(1, 95.0, 'Contribuinte'),
            $this->word(2, 15.0, 'ii'),
            $this->word(2, 95.0, '501234567'),
        ]));

        $this->assertSame("Contribuinte\n501234567", $read->text);
    }

    // A value is found by the words in front of it, along a line. Reassembled
    // as one run, a label would reach into the line below and take the next
    // field's value.
    public function test_it_rebuilds_the_lines_of_the_page()
    {
        $read = (new TesseractTsv(0))->read($this->tsv([
            $this->word(1, 95.0, 'Nome'),
            $this->word(2, 95.0, 'Morada'),
            $this->word(2, 95.0, 'Rua'),
        ]));

        $this->assertSame("Nome\nMorada Rua", $read->text);
    }

    public function test_it_ignores_the_rows_that_describe_blocks_rather_than_words()
    {
        $read = (new TesseractTsv(60))->read($this->tsv([
            "1\t1\t0\t0\t0\t0\t0\t0\t100\t100\t-1\t",
            "2\t1\t1\t0\t0\t0\t0\t0\t100\t100\t-1\t",
            $this->word(1, 95.0, 'Factura'),
            // Tesseract emits empty word rows as spacing; counting them would
            // drag the page's ratio down by however many it happened to write.
            $this->word(1, 95.0, '   '),
        ]));

        $this->assertSame('Factura', $read->text);
        $this->assertSame(1, $read->wordCount);
    }

    // Tesseract lays out lines before it recognises anything, so a page of
    // handwriting comes back with a line on it and no readable word — which is
    // what tells it apart from a blank sheet, where it lays out nothing at all.
    // Counting only words made both "no words", and a photographed page of
    // handwriting was recorded as a blank page read perfectly (ARC-118).
    public function test_it_counts_the_lines_the_engine_laid_out_but_could_not_read()
    {
        $handwritten = (new TesseractTsv(60))->read($this->tsv([
            "4\t1\t1\t1\t1\t0\t0\t0\t100\t100\t-1\t",
            $this->word(1, 95.0, '   '),
        ]));

        $this->assertSame(0, $handwritten->wordCount);
        $this->assertSame(1, $handwritten->lineCount);
        $this->assertSame(0.0, $handwritten->confidentRatio(), 'A page with writing it could not read is not a page read perfectly.');
    }

    public function test_a_page_with_no_layout_at_all_is_a_blank_sheet()
    {
        $blank = (new TesseractTsv(60))->read($this->tsv([]));

        $this->assertSame(0, $blank->lineCount);
        $this->assertSame(1.0, $blank->confidentRatio(), 'A blank sheet was read perfectly and simply has nothing on it.');
    }

    public function test_refusing_every_word_leaves_nothing_rather_than_a_line_of_gaps()
    {
        $read = (new TesseractTsv(60))->read($this->tsv([
            $this->word(1, 20.0, 'i'),
            $this->word(1, 33.0, 'Lo'),
            $this->word(1, 12.0, 'ee'),
        ]));

        $this->assertSame('', $read->text);
        $this->assertSame(3, $read->wordCount);
        $this->assertSame(0, $read->confidentWordCount);
    }
}
