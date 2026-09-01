<?php

declare(strict_types=1);

namespace App\Services\Ocr;

use App\Models\DocumentAttachment;
use App\Services\Ocr\Contracts\OcrEngine;
use Closure;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\PdfToImage\Enums\OutputFormat;
use Spatie\PdfToImage\Pdf as PdfToImage;
use Spatie\PdfToText\Pdf as PdfToText;
use Throwable;

/**
 * Decides how to get text out of one attachment, and does it.
 *
 * The single decision this class exists to make: a PDF that was born digital
 * already carries its text, and OCR would be both slower and less accurate
 * than simply reading it. So the embedded text layer is tried first, and OCR
 * is the fallback for what has none — scans and photographs, which is most of
 * what an archive of physical documents actually holds.
 *
 * `min_text_length` is what separates the two. Scanned PDFs frequently carry a
 * handful of characters from a header, a stamp or a scanner watermark, so
 * "has any text at all" is not a usable test.
 *
 * Everything here works on a temporary local copy of the file: the attachments
 * disk may be S3, and pdftotext, Imagick and tesseract all need a real path.
 */
class AttachmentTextExtractor
{
    public function __construct(private readonly OcrEngine $engine) {}

    /**
     * Extract the text of one attachment.
     *
     * @param DocumentAttachment $attachment The attachment to read; only its disk, path and mime type are used.
     *
     * @return ExtractedText The extracted text and the status explaining it.
     *
     * @throws UnreadableAttachment If the file's bytes cannot be parsed — permanent, do not retry.
     * @throws RuntimeException If the file cannot be read from its disk, or the engine fails.
     */
    public function handle(DocumentAttachment $attachment): ExtractedText
    {
        if (!config('archivum.ocr.enabled')) {
            return ExtractedText::unavailable();
        }

        $mimeType = $attachment->mime_type;

        if ($mimeType === 'application/pdf') {
            return $this->fromPdf($attachment);
        }

        if (Str::startsWith($mimeType, 'image/')) {
            return $this->engine->isAvailable()
                ? $this->withLocalCopy($attachment, fn (string $path): ExtractedText => ExtractedText::completed($this->engine->extract($path)))
                : ExtractedText::unavailable();
        }

        return ExtractedText::skipped();
    }

    /**
     * Read a PDF's embedded text layer, falling back to OCR when it has none.
     *
     * @param DocumentAttachment $attachment The PDF attachment.
     *
     * @return ExtractedText The extracted text and the status explaining it.
     *
     * @throws UnreadableAttachment If the file is not actually a PDF.
     * @throws RuntimeException If the file cannot be read from its disk, or the engine fails.
     */
    private function fromPdf(DocumentAttachment $attachment): ExtractedText
    {
        return $this->withLocalCopy($attachment, function (string $path): ExtractedText {
            if (!$this->looksLikePdf($path)) {
                throw new UnreadableAttachment("Attachment is recorded as a PDF but does not contain one: {$path}");
            }

            $embedded = $this->embeddedText($path);

            if (mb_strlen($embedded) >= (int) config('archivum.ocr.min_text_length')) {
                return ExtractedText::completed($embedded);
            }

            if (!$this->engine->isAvailable()) {
                // The text layer was too thin to trust, and there is no engine
                // to fall back on. Returning the scraps would be worse than
                // saying so: it would look like the document had been read.
                return ExtractedText::unavailable();
            }

            return ExtractedText::completed($this->ocrPdfPages($path));
        });
    }

    /**
     * Whether the file actually begins with a PDF header.
     *
     * The mime type on the attachment came from the upload and is not
     * evidence. Checking here rather than letting Ghostscript find out means a
     * truncated upload fails instantly and quietly, instead of after a
     * subprocess that dumps a PostScript stack trace onto stderr.
     *
     * Searches the first kilobyte rather than the first five bytes, matching
     * the leading junk poppler tolerates in real-world files.
     *
     * @param string $path Absolute path to a local file.
     *
     * @return bool True when a PDF header is present.
     */
    private function looksLikePdf(string $path): bool
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $header = fread($handle, 1024);
        fclose($handle);

        return $header !== false && str_contains($header, '%PDF-');
    }

    /**
     * Read the text layer a PDF already carries, if it has one.
     *
     * A PDF that is pure images has no text layer, and pdftotext also fails
     * outright on some malformed files. Neither is an error worth propagating:
     * both simply mean "fall back to OCR".
     *
     * @param string $path Absolute path to a local PDF.
     *
     * @return string The embedded text, or an empty string when there is none.
     */
    private function embeddedText(string $path): string
    {
        try {
            return mb_trim(PdfToText::getText($path, timeout: (int) config('archivum.ocr.timeout')));
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Rasterize a PDF's pages and OCR each one.
     *
     * Capped at `max_pages`: OCR costs roughly a second of CPU per page, so an
     * unbounded scan would hold a queue worker for minutes.
     *
     * @param string $path Absolute path to a local PDF.
     *
     * @return string The recognised text of every processed page, joined by blank lines.
     *
     * @throws UnreadableAttachment If the file is not a PDF Imagick can open.
     * @throws RuntimeException If a page cannot be recognised.
     */
    private function ocrPdfPages(string $path): string
    {
        $directory = $this->temporaryDirectory();

        try {
            $pdf = (new PdfToImage($path))
                ->resolution((int) config('archivum.ocr.dpi'))
                ->format(OutputFormat::Png);

            $pageCount = min($pdf->pageCount(), (int) config('archivum.ocr.max_pages'));

            if ($pageCount < 1) {
                return '';
            }

            $images = $pdf->selectPages(...range(1, $pageCount))->save($directory);

            $pages = array_map(fn (string $image): string => $this->engine->extract($image), $images);

            return mb_trim(implode("\n\n", array_filter($pages, static fn (string $page): bool => $page !== '')));
        } catch (RuntimeException $exception) {
            // Already ours — an engine failure from the loop above, which is
            // about the engine rather than the file, and stays retryable.
            throw $exception;
        } catch (Throwable $exception) {
            // Imagick refusing the file means the bytes are not the PDF the
            // mime type claims. Permanent, and specifically not the engine's
            // fault, so it must not be retried.
            throw new UnreadableAttachment("Unable to rasterize {$path}: {$exception->getMessage()}", previous: $exception);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    /**
     * Run a callback against a temporary local copy of the attachment's file,
     * deleting it afterwards whether the callback succeeded or threw.
     *
     * The copy keeps the original extension, because Imagick decides how to
     * read a file partly by its name.
     *
     * @param DocumentAttachment $attachment The attachment whose file is copied.
     * @param Closure(string): ExtractedText $callback Receives the absolute path of the local copy.
     *
     * @return ExtractedText Whatever the callback returned.
     *
     * @throws RuntimeException If the file is missing from its disk.
     */
    private function withLocalCopy(DocumentAttachment $attachment, Closure $callback): ExtractedText
    {
        $stream = Storage::disk($attachment->disk)->readStream($attachment->path);

        if ($stream === null) {
            throw new RuntimeException("Attachment file is missing from disk [{$attachment->disk}]: {$attachment->path}");
        }

        $directory = $this->temporaryDirectory();
        $extension = pathinfo($attachment->path, PATHINFO_EXTENSION);
        $path = $directory . '/source' . ($extension === '' ? '' : '.' . $extension);

        try {
            $target = fopen($path, 'wb');

            if ($target === false) {
                throw new RuntimeException("Unable to write a temporary copy of the attachment to {$path}.");
            }

            stream_copy_to_stream($stream, $target);
            fclose($target);

            return $callback($path);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }

            File::deleteDirectory($directory);
        }
    }

    /**
     * Create a fresh empty directory under the system temp path.
     *
     * @return string The absolute path of the new directory.
     */
    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/archivum-ocr-' . Str::uuid()->toString();

        File::ensureDirectoryExists($directory);

        return $directory;
    }
}
