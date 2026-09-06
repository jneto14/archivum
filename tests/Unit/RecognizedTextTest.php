<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ocr\RecognizedText;
use PHPUnit\Framework\TestCase;

/**
 * The arithmetic behind the page floor. Pure enough to need no application,
 * and worth pinning because both edges of it are decisions rather than
 * consequences: a blank page is a perfect reading, and a bad page in a long
 * scan must not condemn the rest of it (ARC-118).
 */
class RecognizedTextTest extends TestCase
{
    public function test_a_page_the_engine_was_sure_of_reports_the_whole_of_it()
    {
        $recognized = RecognizedText::confident('Factura numero 2026 0044');

        $this->assertSame(4, $recognized->wordCount);
        $this->assertSame(4, $recognized->confidentWordCount);
        $this->assertSame(1.0, $recognized->confidentRatio());
    }

    // A blank page was read perfectly and has nothing on it. Returning 0.0
    // here would put every blank sheet in an archive under the page floor and
    // report it as unreadable.
    public function test_a_page_with_no_words_counts_as_fully_confident()
    {
        $this->assertSame(1.0, RecognizedText::empty()->confidentRatio());
        $this->assertSame(1.0, RecognizedText::confident('')->confidentRatio());
    }

    public function test_the_ratio_is_the_share_that_survived()
    {
        $this->assertSame(0.25, (new RecognizedText('one', 20, 5))->confidentRatio());
    }

    // Counts add across pages rather than being averaged per page: one
    // unreadable page in a twenty-page scan must not condemn the other
    // nineteen, and one good page must not rescue a scan that is otherwise
    // noise.
    public function test_joining_pages_sums_the_counts_rather_than_averaging_them()
    {
        $joined = RecognizedText::join([
            new RecognizedText('a clean page', 100, 95),
            new RecognizedText('scrawl', 40, 2),
        ]);

        $this->assertSame(140, $joined->wordCount);
        $this->assertSame(97, $joined->confidentWordCount);
        $this->assertSame("a clean page\n\nscrawl", $joined->text);
    }

    public function test_joining_leaves_out_pages_that_yielded_no_text()
    {
        $joined = RecognizedText::join([
            new RecognizedText('first', 10, 10),
            new RecognizedText('', 10, 0),
            new RecognizedText('third', 10, 10),
        ]);

        $this->assertSame("first\n\nthird", $joined->text, 'A page that yielded nothing must not leave a hole of blank lines in the middle.');
        $this->assertSame(30, $joined->wordCount, 'It still counts against the document: it was read, and nothing survived.');
    }

    public function test_joining_nothing_is_an_empty_reading()
    {
        $joined = RecognizedText::join([]);

        $this->assertSame('', $joined->text);
        $this->assertSame(0, $joined->wordCount);
        $this->assertSame(1.0, $joined->confidentRatio());
    }
}
