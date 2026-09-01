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
        $path = $this->image(null);

        $this->assertSame(
            '',
            $this->engine()->extract($path),
            'Tesseract exits 0 and writes nothing here. That is "no text", not a failure.',
        );
    }

    public function test_an_image_with_text_is_read()
    {
        $path = $this->image('Contador 998877');

        $this->assertStringContainsString('998877', $this->engine()->extract($path));
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
        $engine = new TesseractEngine('por+klingon', 30);

        $this->assertFalse(
            $engine->isAvailable(),
            'Tesseract runs without a language pack and recognises nothing, which is far harder to diagnose than "unavailable".',
        );
    }

    /**
     * @return OcrEngine The engine as the application binds it.
     */
    private function engine(): OcrEngine
    {
        return app(OcrEngine::class);
    }

    /**
     * Render a PNG, optionally with a line of text on it.
     *
     * @param string|null $text The text to draw, or null for a blank page.
     *
     * @return string Absolute path to the written image.
     */
    private function image(?string $text): string
    {
        $image = imagecreatetruecolor(900, 140);
        imagefilledrectangle($image, 0, 0, 899, 139, (int) imagecolorallocate($image, 255, 255, 255));

        if ($text !== null) {
            imagettftext($image, 30, 0, 30, 85, (int) imagecolorallocate($image, 0, 0, 0), self::FONT, $text);
        }

        $path = sys_get_temp_dir() . '/archivum-engine-' . uniqid() . '.png';
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }
}
