<?php

declare(strict_types=1);

namespace App\Services\Ocr;

use App\Services\Ocr\Contracts\OcrEngine;
use RuntimeException;
use Symfony\Component\Process\Process;
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
 *
 * The command is built with the package's `Command`/`Option` classes — that is
 * the escaping and version handling worth having — but run through Symfony
 * Process rather than the package's own `run()`. The package treats empty
 * output as a failure, and tesseract legitimately produces empty output, with
 * exit code 0, for an image that simply has no legible text: a photograph, a
 * logo, a blank page. Going through Process means the exit code decides, which
 * is the only thing that actually distinguishes "found nothing" from "broke".
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
     * @return string The recognised text, trimmed. Empty when the image holds no legible text.
     *
     * @throws RuntimeException If tesseract exits unsuccessfully or is killed by the timeout.
     */
    public function extract(string $imagePath): string
    {
        $command = new Command($imagePath);

        // Write to stdout rather than a temp file, so there is nothing to clean
        // up and the result is read straight off the process.
        $command->useFileAsOutput = false;
        $command->options[] = Option::lang(...$this->languageCodes());

        $process = Process::fromShellCommandline((string) $command);
        $process->setTimeout((float) $this->timeout);

        try {
            $process->run();
        } catch (Throwable $exception) {
            throw new RuntimeException("Could not run tesseract: {$exception->getMessage()}", previous: $exception);
        }

        if (!$process->isSuccessful()) {
            throw new RuntimeException($this->failureMessage($process));
        }

        return mb_trim($process->getOutput());
    }

    /**
     * Build a failure message worth showing to a workspace admin.
     *
     * Tesseract writes progress notes to stderr even on a good run, so only its
     * last line is kept — that is where the actual error lands — and the
     * temporary image path is left out entirely: it is deleted moments later
     * and means nothing to whoever reads the Tasks page.
     *
     * @param Process $process The finished, unsuccessful process.
     *
     * @return string A single-line description of the failure.
     */
    private function failureMessage(Process $process): string
    {
        $lines = array_values(array_filter(
            array_map(trim(...), explode("\n", $process->getErrorOutput())),
            static fn (string $line): bool => $line !== '',
        ));

        $detail = $lines === [] ? 'no error output' : end($lines);

        return "Tesseract exited with code {$process->getExitCode()}: {$detail}";
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
