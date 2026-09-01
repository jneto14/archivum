<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Enums\OcrStatus;
use App\Models\DocumentAttachment;
use App\Services\Ocr\AttachmentTextExtractor;
use App\Services\Ocr\Contracts\OcrEngine;
use App\Services\Ocr\UnreadableAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Covers the one decision this feature turns on: a PDF that already carries a
 * text layer is read directly, and only what has none is sent to OCR.
 *
 * The OCR engine is faked throughout, so these do not need the tesseract
 * binary. `pdftotext` and Imagick are exercised for real — the branch is about
 * what they return, and faking that would test nothing.
 */
class AttachmentTextExtractorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        config()->set('archivum.ocr.enabled', true);
        config()->set('archivum.ocr.min_text_length', 20);
        config()->set('archivum.ocr.max_pages', 5);
    }

    public function test_a_pdf_with_a_text_layer_is_read_without_running_ocr()
    {
        $engine = $this->fakeEngine('text from ocr');
        $attachment = $this->attachmentHolding($this->pdfContaining('Fatura de electricidade numero 12345'), 'invoice.pdf', 'application/pdf');

        $extracted = app(AttachmentTextExtractor::class)->handle($attachment);

        $this->assertSame(OcrStatus::Completed, $extracted->status);
        $this->assertStringContainsString('12345', $extracted->text);
        $this->assertSame([], $engine->calls, 'A PDF that already carries its text must never reach the OCR engine.');
    }

    public function test_a_pdf_without_a_text_layer_falls_back_to_ocr()
    {
        $engine = $this->fakeEngine('text from ocr');
        $attachment = $this->attachmentHolding($this->pdfContaining(''), 'scan.pdf', 'application/pdf');

        $extracted = app(AttachmentTextExtractor::class)->handle($attachment);

        $this->assertSame(OcrStatus::Completed, $extracted->status);
        $this->assertSame('text from ocr', $extracted->text);
        $this->assertCount(1, $engine->calls, 'A page with no text layer must be rasterized and sent to the engine.');
    }

    public function test_a_pdf_whose_text_layer_is_too_thin_to_trust_falls_back_to_ocr()
    {
        $engine = $this->fakeEngine('text from ocr');

        // Shorter than min_text_length: the stray characters a scanner stamps
        // onto an otherwise imaged page, not a real text layer.
        $attachment = $this->attachmentHolding($this->pdfContaining('Page 1'), 'scan.pdf', 'application/pdf');

        $extracted = app(AttachmentTextExtractor::class)->handle($attachment);

        $this->assertSame('text from ocr', $extracted->text);
        $this->assertCount(1, $engine->calls);
    }

    public function test_an_image_goes_straight_to_the_engine()
    {
        $engine = $this->fakeEngine('handwritten note');
        $attachment = $this->attachmentHolding('not really a png', 'photo.png', 'image/png');

        $extracted = app(AttachmentTextExtractor::class)->handle($attachment);

        $this->assertSame(OcrStatus::Completed, $extracted->status);
        $this->assertSame('handwritten note', $extracted->text);
        $this->assertCount(1, $engine->calls);
    }

    public function test_a_file_that_is_neither_pdf_nor_image_is_skipped()
    {
        $engine = $this->fakeEngine('never called');
        $attachment = $this->attachmentHolding('id,total', 'ledger.csv', 'text/csv');

        $extracted = app(AttachmentTextExtractor::class)->handle($attachment);

        $this->assertSame(OcrStatus::Skipped, $extracted->status);
        $this->assertSame('', $extracted->text);
        $this->assertSame([], $engine->calls);
    }

    public function test_nothing_is_extracted_when_the_feature_is_switched_off()
    {
        config()->set('archivum.ocr.enabled', false);

        $engine = $this->fakeEngine('never called');
        $attachment = $this->attachmentHolding('not really a png', 'photo.png', 'image/png');

        $extracted = app(AttachmentTextExtractor::class)->handle($attachment);

        $this->assertSame(OcrStatus::Unavailable, $extracted->status);
        $this->assertSame([], $engine->calls);
    }

    public function test_an_installation_without_the_engine_reports_unavailable_rather_than_failing()
    {
        $this->fakeEngine('never called', available: false);
        $attachment = $this->attachmentHolding('not really a png', 'photo.png', 'image/png');

        $extracted = app(AttachmentTextExtractor::class)->handle($attachment);

        $this->assertSame(OcrStatus::Unavailable, $extracted->status);
    }

    public function test_a_scanned_pdf_reports_unavailable_when_the_engine_is_missing()
    {
        $this->fakeEngine('never called', available: false);
        $attachment = $this->attachmentHolding($this->pdfContaining(''), 'scan.pdf', 'application/pdf');

        $extracted = app(AttachmentTextExtractor::class)->handle($attachment);

        $this->assertSame(
            OcrStatus::Unavailable,
            $extracted->status,
            'Returning the scraps of a thin text layer would look like the document had been read.',
        );
    }

    public function test_a_file_that_is_not_the_pdf_it_claims_to_be_is_reported_as_unreadable()
    {
        $this->fakeEngine('never called');
        $attachment = $this->attachmentHolding('this is not a pdf at all', 'broken.pdf', 'application/pdf');

        // Distinct from a generic failure: the bytes will not improve on a
        // retry, and ExtractAttachmentText relies on the type to know that.
        $this->expectException(UnreadableAttachment::class);

        app(AttachmentTextExtractor::class)->handle($attachment);
    }

    public function test_a_missing_file_is_an_error_rather_than_an_empty_result()
    {
        $this->fakeEngine('never called');

        $attachment = DocumentAttachment::factory()->create([
            'disk' => 'local',
            'path' => 'documents/gone/missing.pdf',
            'mime_type' => 'application/pdf',
        ]);

        $this->expectException(RuntimeException::class);

        app(AttachmentTextExtractor::class)->handle($attachment);
    }

    /**
     * Bind an OCR engine that records what it was asked to read.
     *
     * @param string $text What the engine "recognises".
     * @param bool $available Whether the engine reports itself as installed.
     *
     * @return OcrEngine The bound engine, with a public `$calls` list of image paths.
     */
    private function fakeEngine(string $text, bool $available = true): OcrEngine
    {
        $engine = new class($text, $available) implements OcrEngine
        {
            /** @var list<string> Paths this engine was asked to read. */
            public array $calls = [];

            public function __construct(private readonly string $text, private readonly bool $available) {}

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function extract(string $imagePath): string
            {
                $this->calls[] = $imagePath;

                return $this->text;
            }
        };

        $this->app->instance(OcrEngine::class, $engine);

        return $engine;
    }

    /**
     * Store $contents on the fake disk and return an attachment pointing at it.
     *
     * @param string $contents The file's bytes.
     * @param string $filename The stored filename, whose extension matters to Imagick.
     * @param string $mimeType The attachment's recorded mime type, which is what the extractor branches on.
     *
     * @return DocumentAttachment The persisted attachment.
     */
    private function attachmentHolding(string $contents, string $filename, string $mimeType): DocumentAttachment
    {
        $path = 'documents/fixtures/' . $filename;

        Storage::disk('local')->put($path, $contents);

        return DocumentAttachment::factory()->create([
            'disk' => 'local',
            'path' => $path,
            'filename' => $filename,
            'mime_type' => $mimeType,
        ]);
    }

    /**
     * Build a valid single-page PDF carrying $text as a real text layer.
     *
     * Written out by hand rather than committed as a fixture so that the two
     * cases the pipeline turns on — a page with a text layer and a page
     * without — differ by one argument and are readable in the test itself.
     * Pass an empty string for a page with no text layer at all, which is what
     * a scan looks like to pdftotext.
     *
     * @param string $text ASCII text to place on the page, or '' for an empty page.
     *
     * @return string The PDF's bytes.
     */
    private function pdfContaining(string $text): string
    {
        $stream = $text === ''
            ? ''
            : 'BT /F1 12 Tf 72 720 Td (' . addcslashes($text, '()\\') . ') Tj ET';

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] '
                . '/Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            '<< /Length ' . mb_strlen($stream, '8bit') . " >>\nstream\n" . $stream . "\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $index => $object) {
            // Byte offsets, so '8bit' rather than character counts — the xref
            // table below is what makes this a readable PDF.
            $offsets[] = mb_strlen($pdf, '8bit');
            $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }

        $startXref = mb_strlen($pdf, '8bit');
        $size = count($objects) + 1;

        $pdf .= "xref\n0 " . $size . "\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf . "trailer\n<< /Size " . $size . " /Root 1 0 R >>\nstartxref\n" . $startXref . "\n%%EOF\n";
    }
}
