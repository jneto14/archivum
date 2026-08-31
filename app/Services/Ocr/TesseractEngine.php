<?php

declare(strict_types=1);

namespace App\Services\Ocr;

use App\Services\Ocr\Contracts\OcrEngine;
use RuntimeException;
use thiagoalessio\TesseractOCR\Command;
use thiagoalessio\TesseractOCR\Option;
use thiagoalessio\TesseractOCR\TesseractOCR;
use Throwable;

/**
 * OCR through the `tesseract` command-line binary.
 *
 * The binary and one language pack per configured language must be installed;
 * they are not PHP extensions and `composer install` does not bring them. See
 * the OCR block in `config/archivum.php` and this project's Dockerfile.
 */
class TesseractEngine implements OcrEngine
{
    /**
     * @param string $languages Tesseract language codes joined with "+", e.g. "por+eng".
     * @param int $timeout Seconds a single recognition may run before it is killed.
     */
    public function __construct(
        private readonly string $languages,
        private readonly int $timeout,
    ) {}

    /**
     * Whether the binary is installed and the configured languages are present.
     *
     * Checks the language packs too, not just the binary: Tesseract runs
     * happily without them and returns nothing, which looks identical to a
     * blank page and is far harder to diagnose than a clear "unavailable".
     *
     * @return bool True when tesseract can be run with every configured language.
     */
    public function isAvailable(): bool
    {
        try {
            $installed = (new TesseractOCR())->availableLanguages();

            foreach ($this->languageCodes() as $language) {
                if (!in_array($language, $installed, true)) {
                    return false;
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param string $imagePath Absolute path to a readable local raster image.
     *
     * @return string The recognised text, trimmed.
     *
     * @throws RuntimeException If tesseract exits unsuccessfully or is killed by the timeout.
     */
    public function extract(string $imagePath): string
    {
        // Built through Command/Option rather than the fluent `->lang(...)`,
        // which the package implements with __call and is therefore invisible
        // to static analysis. Same command line, one that can be checked.
        $command = new Command($imagePath);
        $command->options[] = Option::lang(...$this->languageCodes());

        try {
            return (new TesseractOCR($imagePath, $command))->run($this->timeout);
        } catch (Throwable $exception) {
            throw new RuntimeException("Tesseract failed on {$imagePath}: {$exception->getMessage()}", previous: $exception);
        }
    }

    /**
     * Split the configured languages into individual Tesseract codes.
     *
     * @return list<string> One code per configured language, e.g. ['por', 'eng'].
     */
    private function languageCodes(): array
    {
        return array_values(array_filter(explode('+', $this->languages)));
    }
}
