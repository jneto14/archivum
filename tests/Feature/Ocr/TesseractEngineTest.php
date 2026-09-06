<?php

declare(strict_types=1);

namespace Tests\Feature\Ocr;

use App\Services\Ocr\Contracts\OcrEngine;
use App\Services\Ocr\TesseractEngine;
use RuntimeException;
use Tests\TestCase;

/**
 * The one place that talks to the real `tesseract` binary, and therefore the
 * one test in the suite that needs it installed. Everywhere else the engine is
 * faked through `OcrEngine`.
 *
 * It exists because of a bug this was shipped with: the wrapper package treats
 * empty output as a failure, while tesseract exits 0 and writes nothing for an
 * image that simply has no legible text. Every logo, chart and blank scan was
 * being recorded as a failed extraction.
 */
class TesseractEngineTest extends TestCase
{
    private const string FONT = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';

    public function test_an_image_with_no_legible_text_reads_as_empty_rather_than_failing()
    {
        $path = $this->image();

        $this->assertSame(
            '',
            $this->engine()->extract($path)->text,
            'Tesseract exits 0 and writes nothing here. That is "no text", not a failure.',
        );
    }

    public function test_an_image_with_text_is_read()
    {
        $path = $this->image('Contador 998877');

        $this->assertStringContainsString('998877', $this->engine()->extract($path)->text);
    }

    public function test_a_genuine_failure_still_raises_and_says_something_useful()
    {
        try {
            $this->engine()->extract(sys_get_temp_dir() . '/archivum-missing-' . uniqid() . '.png');
            $this->fail('A file tesseract cannot read must raise.');
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
        }

        $this->assertStringContainsString('exited with code', $message);

        // The temporary path is deleted moments later and means nothing to
        // whoever reads the Tasks page, so it must not end up in the message.
        $this->assertStringNotContainsString(sys_get_temp_dir(), $message);
        $this->assertStringNotContainsString("\n", $message, 'A task row shows one line, not a stack dump.');
    }

    public function test_it_reports_itself_unavailable_when_a_configured_language_is_not_installed()
    {
        $engine = new TesseractEngine('por+klingon', 30, 60);

        $this->assertFalse(
            $engine->isAvailable(),
            'Tesseract runs without a language pack and recognises nothing, which is far harder to diagnose than "unavailable".',
        );
    }

    public function test_it_reports_how_much_of_what_it_read_it_was_sure_of()
    {
        $recognized = $this->engine()->extract($this->image('Contador 998877'));

        $this->assertGreaterThan(0, $recognized->wordCount);
        $this->assertSame(
            $recognized->wordCount,
            $recognized->confidentWordCount,
            'Clean printed text scores in the nineties; nothing here should fall under the floor.',
        );
        $this->assertSame(1.0, $recognized->confidentRatio());
    }

    // The filter this exists for, exercised from the other end: printed text
    // scores 91-96, so a floor above that must drop all of it. Proving it on
    // real handwriting would need handwriting to render, but the mechanism
    // being tested — a word's score deciding whether it is kept — is the same
    // one (ARC-118).
    public function test_words_below_the_configured_floor_are_dropped()
    {
        config()->set('archivum.ocr.min_word_confidence', 99);

        $recognized = $this->engine()->extract($this->image('Contador 998877'));

        $this->assertSame('', $recognized->text);
        $this->assertGreaterThan(0, $recognized->wordCount, 'The words were read, then refused — not never read.');
        $this->assertSame(0, $recognized->confidentWordCount);
        $this->assertSame(0.0, $recognized->confidentRatio());
    }

    // A value is recognised by the words in front of it, along a line. Text
    // reassembled as one long run would join the end of one line to the start
    // of the next and invent labels nobody wrote.
    public function test_it_keeps_the_line_structure_of_the_page()
    {
        $recognized = $this->engine()->extract(
            $this->image('Factura 2026', 'Contador 998877'),
        );

        $lines = explode("\n", $recognized->text);

        $this->assertCount(2, $lines);
        $this->assertStringContainsString('2026', $lines[0]);
        $this->assertStringContainsString('998877', $lines[1]);
    }

    /**
     * @return OcrEngine The engine as the application binds it.
     */
    private function engine(): OcrEngine
    {
        return app(OcrEngine::class);
    }

    /**
     * Render a PNG carrying the given lines of text, or a blank one if given
     * none.
     *
     * @param string ...$lines The lines to draw, top to bottom.
     *
     * @return string Absolute path to the written image.
     */
    private function image(string ...$lines): string
    {
        $image = imagecreatetruecolor(900, 90 + (count($lines) * 70));
        imagefilledrectangle($image, 0, 0, 899, imagesy($image) - 1, (int) imagecolorallocate($image, 255, 255, 255));

        foreach (array_values($lines) as $index => $line) {
            imagettftext($image, 30, 0, 30, 70 + ($index * 70), (int) imagecolorallocate($image, 0, 0, 0), self::FONT, $line);
        }

        $path = sys_get_temp_dir() . '/archivum-engine-' . uniqid() . '.png';
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }
}
