<?php

declare(strict_types=1);

namespace App\Services\Ocr\Contracts;

use App\Services\Ocr\RecognizedText;
use RuntimeException;

/**
 * Reads text out of a single raster image.
 *
 * The seam that keeps Tesseract from leaking through the application. Only
 * `TesseractEngine` knows the binary exists; everything else — the extractor,
 * the job, the tests — talks to this. A hosted OCR API can be added later as a
 * second implementation and selected in `config('archivum.ocr')`, without
 * touching the pipeline that decides *when* to OCR.
 *
 * Deliberately image-only. PDFs are not an engine's problem: whether a PDF
 * needs OCR at all is a decision `AttachmentTextExtractor` makes, and by the
 * time an engine is called the pages are already bitmaps.
 *
 * Language configuration belongs to the implementation, not to this contract —
 * a hosted engine would not take Tesseract's `por+eng` codes.
 */
interface OcrEngine
{
    /**
     * Whether this engine can actually run here.
     *
     * Implementations must answer without throwing, so a missing binary
     * degrades to "no text extracted" rather than an exception on every
     * upload.
     *
     * @return bool True when the engine is usable on this installation.
     */
    public function isAvailable(): bool;

    /**
     * Extract the text from one image.
     *
     * Implementations return the words they were confident about, and how
     * many they were not. An engine that scores nothing may report every word
     * as confident; what it must not do is present a guess as a reading, since
     * the caller has no other way to tell the two apart.
     *
     * @param string $imagePath Absolute path to a readable local raster image.
     *
     * @return RecognizedText The recognised text and what it is worth. Empty when the image holds no legible text.
     *
     * @throws RuntimeException If recognition fails, as opposed to finding nothing.
     */
    public function extract(string $imagePath): RecognizedText;
}
