<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Actions\Documents\CreateDocument;
use App\Actions\Documents\DeleteAttachment;
use App\Actions\Documents\SearchDocuments;
use App\Enums\OcrStatus;
use App\Enums\WorkspaceRole;
use App\Jobs\ExtractAttachmentText;
use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\DocumentType;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Ocr\AttachmentTextExtractor;
use App\Services\Ocr\Contracts\OcrEngine;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Covers the job around the extractor: what it records on the attachment, how
 * that reaches the document's search index, and what happens when it fails.
 *
 * Uses DatabaseMigrations rather than RefreshDatabase because the search
 * assertion goes through MySQL's full-text index, and InnoDB's FTS does not
 * see rows written inside an uncommitted transaction.
 */
class ExtractAttachmentTextTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        config()->set('archivum.ocr.enabled', true);
        config()->set('archivum.ocr.min_text_length', 20);
    }

    public function test_uploading_an_attachment_queues_its_text_extraction()
    {
        Queue::fake();

        $document = $this->document();

        $this->actingAs($document->creator)->post(route('attachments.store', $document), [
            'file' => UploadedFile::fake()->create('scan.pdf', 10, 'application/pdf'),
        ])->assertRedirect();

        Queue::assertPushed(ExtractAttachmentText::class);
    }

    public function test_nothing_is_queued_when_extraction_is_switched_off()
    {
        Queue::fake();
        config()->set('archivum.ocr.enabled', false);

        $document = $this->document();

        $this->actingAs($document->creator)->post(route('attachments.store', $document), [
            'file' => UploadedFile::fake()->create('scan.pdf', 10, 'application/pdf'),
        ])->assertRedirect();

        Queue::assertNothingPushed();

        $attachment = DocumentAttachment::query()->where('document_id', $document->id)->firstOrFail();

        $this->assertSame(
            OcrStatus::Unavailable,
            $attachment->ocr_status,
            'With extraction off the attachment must settle immediately, not sit on "pending" forever.',
        );
    }

    public function test_the_job_records_the_text_on_the_attachment_and_mirrors_it_onto_the_document()
    {
        $this->fakeEngine('Contador numero 998877');
        $attachment = $this->attachment($this->document(), 'photo.png', 'image/png');

        (new ExtractAttachmentText($attachment))->handle(app(AttachmentTextExtractor::class));

        $attachment->refresh();

        $this->assertSame(OcrStatus::Completed, $attachment->ocr_status);
        $this->assertSame('Contador numero 998877', $attachment->ocr_text);
        $this->assertNotNull($attachment->ocr_extracted_at);
        $this->assertSame('Contador numero 998877', $attachment->document->refresh()->ocr_text);
    }

    public function test_a_document_gathers_the_text_of_all_its_attachments()
    {
        $document = $this->document();

        $this->fakeEngine('first page');
        $first = $this->attachment($document, 'one.png', 'image/png');
        (new ExtractAttachmentText($first))->handle(app(AttachmentTextExtractor::class));

        $this->fakeEngine('second page');
        $second = $this->attachment($document, 'two.png', 'image/png');
        (new ExtractAttachmentText($second))->handle(app(AttachmentTextExtractor::class));

        $text = (string) $document->refresh()->ocr_text;

        $this->assertStringContainsString('first page', $text);
        $this->assertStringContainsString('second page', $text);
    }

    public function test_a_failing_engine_leaves_the_error_on_the_attachment()
    {
        $this->failingEngine('tesseract exited with status 1');
        $attachment = $this->attachment($this->document(), 'photo.png', 'image/png');

        try {
            (new ExtractAttachmentText($attachment))->handle(app(AttachmentTextExtractor::class));
            $this->fail('The job must rethrow so the queue can retry it.');
        } catch (RuntimeException) {
            // Expected.
        }

        $attachment->refresh();

        $this->assertSame(OcrStatus::Failed, $attachment->ocr_status);
        $this->assertStringContainsString('tesseract exited with status 1', (string) $attachment->ocr_error);
    }

    public function test_a_corrupt_file_is_recorded_without_being_retried()
    {
        $this->fakeEngine('never called');

        $document = $this->document();
        $path = 'documents/' . $document->id . '/broken.pdf';
        Storage::disk('local')->put($path, 'this is not a pdf at all');

        $attachment = DocumentAttachment::factory()->for($document)->create([
            'uploaded_by' => $document->created_by,
            'disk' => 'local',
            'path' => $path,
            'filename' => 'broken.pdf',
            'mime_type' => 'application/pdf',
        ]);

        // Deliberately not wrapped in expectException: the job must return
        // normally, so that the queue does not retry a file that will never
        // parse — and so that a corrupt upload cannot fail the upload request
        // on a `sync` queue.
        (new ExtractAttachmentText($attachment))->handle(app(AttachmentTextExtractor::class));

        $attachment->refresh();

        $this->assertSame(OcrStatus::Failed, $attachment->ocr_status);
        $this->assertNotNull($attachment->ocr_error);
    }

    public function test_uploading_a_corrupt_file_still_succeeds()
    {
        $this->fakeEngine('never called');

        $document = $this->document();

        // UploadedFile::fake()->create() produces zero-filled bytes, so this is
        // a file claiming to be a PDF that no PDF reader can open — the same
        // shape as a truncated upload from a real user.
        $this->actingAs($document->creator)->post(route('attachments.store', $document), [
            'file' => UploadedFile::fake()->create('scan.pdf', 10, 'application/pdf'),
        ])->assertRedirect();

        $attachment = DocumentAttachment::query()->where('document_id', $document->id)->firstOrFail();

        $this->assertSame(OcrStatus::Failed, $attachment->ocr_status);
        Storage::disk('local')->assertExists($attachment->path);
    }

    public function test_a_document_is_found_by_words_that_appear_only_inside_an_attachment()
    {
        $this->fakeEngine('Aviso de corte por falta de pagamento referencia MMXCII');

        $document = $this->document('Untitled scan');
        $attachment = $this->attachment($document, 'photo.png', 'image/png');

        (new ExtractAttachmentText($attachment))->handle(app(AttachmentTextExtractor::class));

        $results = app(SearchDocuments::class)->handle($document->workspace, 'MMXCII', []);

        $this->assertCount(1, $results->items());
        $this->assertSame($document->id, $results->items()[0]->id);
    }

    public function test_deleting_an_attachment_takes_its_text_out_of_the_document()
    {
        $this->fakeEngine('Aviso de corte referencia MMXCII');

        $document = $this->document();
        $attachment = $this->attachment($document, 'photo.png', 'image/png');

        (new ExtractAttachmentText($attachment))->handle(app(AttachmentTextExtractor::class));
        $this->assertNotNull($document->refresh()->ocr_text);

        app(DeleteAttachment::class)->handle($attachment);

        $this->assertNull(
            $document->refresh()->ocr_text,
            'Text from a removed scan must stop being searchable.',
        );
    }

    /**
     * Create a document with a workspace, an admin creator and a type.
     *
     * @param string $title The document's title.
     *
     * @return Document The persisted document, with `creator` loaded.
     */
    private function document(string $title = 'Invoice'): Document
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $type = DocumentType::factory()->for($workspace)->create();

        return app(CreateDocument::class)->handle($workspace, $member->user, $type, $title, null, null);
    }

    /**
     * Attach a stored file to $document.
     *
     * @param Document $document The owning document.
     * @param string $filename The stored filename.
     * @param string $mimeType The attachment's mime type.
     *
     * @return DocumentAttachment The persisted attachment.
     */
    private function attachment(Document $document, string $filename, string $mimeType): DocumentAttachment
    {
        $path = 'documents/' . $document->id . '/' . $filename;

        Storage::disk('local')->put($path, 'stored bytes');

        return DocumentAttachment::factory()->for($document)->create([
            'uploaded_by' => $document->created_by ?? User::factory(),
            'disk' => 'local',
            'path' => $path,
            'filename' => $filename,
            'mime_type' => $mimeType,
        ]);
    }

    /**
     * Bind an OCR engine that always recognises $text.
     *
     * @param string $text What the engine returns.
     *
     * @return void No return value; binds the engine into the container.
     */
    private function fakeEngine(string $text): void
    {
        $this->app->instance(OcrEngine::class, new class($text) implements OcrEngine
        {
            public function __construct(private readonly string $text) {}

            public function isAvailable(): bool
            {
                return true;
            }

            public function extract(string $imagePath): string
            {
                return $this->text;
            }
        });
    }

    /**
     * Bind an OCR engine that is installed but throws when used.
     *
     * @param string $message The failure message.
     *
     * @return void No return value; binds the engine into the container.
     */
    private function failingEngine(string $message): void
    {
        $this->app->instance(OcrEngine::class, new class($message) implements OcrEngine
        {
            public function __construct(private readonly string $message) {}

            public function isAvailable(): bool
            {
                return true;
            }

            public function extract(string $imagePath): string
            {
                throw new RuntimeException($this->message);
            }
        });
    }
}
