<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Actions\Documents\CreateDocument;
use App\Actions\Documents\DeleteAttachment;
use App\Actions\Documents\SearchDocuments;
use App\Actions\Workspace\RetryTask;
use App\Enums\OcrStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Enums\WorkspaceRole;
use App\Jobs\ExtractAttachmentText;
use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\DocumentType;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Ocr\AttachmentTextExtractor;
use App\Services\Ocr\Contracts\OcrEngine;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
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

        $this->runExtraction($attachment);

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
        $this->runExtraction($first);

        $this->fakeEngine('second page');
        $second = $this->attachment($document, 'two.png', 'image/png');
        $this->runExtraction($second);

        $text = (string) $document->refresh()->ocr_text;

        $this->assertStringContainsString('first page', $text);
        $this->assertStringContainsString('second page', $text);
    }

    public function test_a_failing_engine_leaves_the_error_on_the_attachment()
    {
        $this->failingEngine('tesseract exited with status 1');
        $attachment = $this->attachment($this->document(), 'photo.png', 'image/png');

        try {
            $this->runExtraction($attachment);
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
        $this->runExtraction($attachment);

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

        $this->runExtraction($attachment);

        $results = app(SearchDocuments::class)->handle($document->workspace, 'MMXCII', []);

        $this->assertCount(1, $results->items());
        $this->assertSame($document->id, $results->items()[0]->id);
    }

    public function test_deleting_an_attachment_takes_its_text_out_of_the_document()
    {
        $this->fakeEngine('Aviso de corte referencia MMXCII');

        $document = $this->document();
        $attachment = $this->attachment($document, 'photo.png', 'image/png');

        $this->runExtraction($attachment);
        $this->assertNotNull($document->refresh()->ocr_text);

        app(DeleteAttachment::class)->handle($attachment);

        $this->assertNull(
            $document->refresh()->ocr_text,
            'Text from a removed scan must stop being searchable.',
        );
    }

    public function test_an_upload_creates_a_task_naming_the_file()
    {
        Queue::fake();

        $document = $this->document();

        $this->actingAs($document->creator)->post(route('attachments.store', $document), [
            'file' => UploadedFile::fake()->create('contrato.pdf', 10, 'application/pdf'),
        ])->assertRedirect();

        $task = Task::query()->where('type', TaskType::AttachmentTextExtraction)->firstOrFail();

        $this->assertSame(TaskStatus::Queued, $task->status);
        $this->assertSame($document->workspace_id, $task->workspace_id);
        $this->assertSame($document->created_by, $task->user_id);

        // The filename lives in the payload, not only the result, because
        // Task::markFailed() replaces the result — and a failed row that cannot
        // say which file it was is not worth showing.
        $this->assertSame('contrato.pdf', $task->payload['filename']);
    }

    public function test_extractions_are_not_serialised_behind_a_workspace_lock()
    {
        Queue::fake();

        $document = $this->document();

        foreach (['one.pdf', 'two.pdf'] as $filename) {
            $this->actingAs($document->creator)->post(route('attachments.store', $document), [
                'file' => UploadedFile::fake()->create($filename, 10, 'application/pdf'),
            ])->assertRedirect();
        }

        // An export would have refused the second one. Extraction is scoped to a
        // single file, so both are queued — see TaskType::lockKey().
        $this->assertSame(2, Task::query()->where('type', TaskType::AttachmentTextExtraction)->count());
    }

    public function test_the_task_follows_the_extraction_it_tracks()
    {
        $this->fakeEngine('Contador numero 998877');
        $completed = $this->runExtraction($this->attachment($this->document(), 'ok.png', 'image/png'));

        $this->assertSame(TaskStatus::Completed, $completed->refresh()->status);
        $this->assertSame('ok.png', $completed->result['filename']);

        $this->failingEngine('tesseract exited with status 1');
        $attachment = $this->attachment($this->document(), 'bad.png', 'image/png');

        try {
            $failed = $this->runExtraction($attachment);
        } catch (RuntimeException) {
            $failed = Task::query()->where('payload->attachment_id', $attachment->id)->firstOrFail();
        }

        $this->assertSame(TaskStatus::Failed, $failed->refresh()->status);
        $this->assertStringContainsString('tesseract exited with status 1', $failed->result['error']);
    }

    public function test_a_failed_extraction_can_be_retried_from_the_tasks_page()
    {
        Queue::fake();

        $attachment = $this->attachment($this->document(), 'photo.png', 'image/png');
        $task = $this->failedTaskFor($attachment);

        app(RetryTask::class)->handle($task);

        $this->assertSame(TaskStatus::Queued, $task->refresh()->status);
        Queue::assertPushed(ExtractAttachmentText::class);
    }

    public function test_retrying_an_extraction_whose_file_is_gone_fails_without_requeueing()
    {
        Queue::fake();

        $attachment = $this->attachment($this->document(), 'photo.png', 'image/png');
        $task = $this->failedTaskFor($attachment);

        $attachment->delete();

        try {
            app(RetryTask::class)->handle($task);
            $this->fail('Retrying a task whose attachment is gone must be refused.');
        } catch (ValidationException) {
            // Expected.
        }

        $this->assertSame(
            TaskStatus::Failed,
            $task->refresh()->status,
            'A refused retry must leave the task failed, not queued with nothing to run it.',
        );
        Queue::assertNothingPushed();
    }

    /**
     * Build a failed text extraction task for $attachment.
     *
     * @param DocumentAttachment $attachment The attachment the task refers to.
     *
     * @return Task The failed task.
     */
    private function failedTaskFor(DocumentAttachment $attachment): Task
    {
        return Task::query()->create([
            'workspace_id' => $attachment->document->workspace_id,
            'user_id' => $attachment->uploaded_by,
            'type' => TaskType::AttachmentTextExtraction,
            'status' => TaskStatus::Failed,
            'payload' => [
                'attachment_id' => $attachment->id,
                'document_id' => $attachment->document_id,
                'filename' => $attachment->filename,
            ],
            'result' => ['error' => 'tesseract exited with status 1'],
        ]);
    }

    /**
     * Create the task row an upload would have created, then run the job.
     *
     * @param DocumentAttachment $attachment The attachment to extract.
     *
     * @return Task The task the job drove, for assertions about the Tasks page.
     */
    private function runExtraction(DocumentAttachment $attachment): Task
    {
        $task = Task::query()->create([
            'workspace_id' => $attachment->document->workspace_id,
            'user_id' => $attachment->uploaded_by,
            'type' => TaskType::AttachmentTextExtraction,
            'status' => TaskStatus::Queued,
            'payload' => [
                'attachment_id' => $attachment->id,
                'document_id' => $attachment->document_id,
                'filename' => $attachment->filename,
            ],
        ]);

        (new ExtractAttachmentText($attachment, $task))->handle(app(AttachmentTextExtractor::class));

        return $task;
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
