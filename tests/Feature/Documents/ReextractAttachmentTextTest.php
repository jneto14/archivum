<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Actions\Documents\CreateDocument;
use App\Actions\Documents\FindDuplicateAttachment;
use App\Actions\Documents\SuggestDocumentMetadata;
use App\Actions\Workspace\RetryTask;
use App\Enums\OcrStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Enums\WorkspaceRole;
use App\Jobs\ExtractAttachmentText;
use App\Jobs\QueueWorkspaceReextractions;
use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\DocumentType;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Ocr\AttachmentTextExtractor;
use App\Services\Ocr\Contracts\OcrEngine;
use App\Services\Ocr\RecognizedText;
use App\Services\Ocr\TextFingerprint;
use App\Support\ReextractionFilter;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Covers reading an attachment that is already in the archive a second time
 * (ARC-122): the per-file button, the workspace-wide sweep, the console
 * command, and what a second reading does to what the first one left behind.
 */
class ReextractAttachmentTextTest extends TestCase
{
    use RefreshDatabase;

    /** @var string A page's worth of text. A short fixture is deliberately never fingerprinted — see TextFingerprintTest. */
    private const INVOICE = <<<'TEXT'
        Fatura FT2026/1240 emitida em 20/08/2026 pela Exemplo Lda, com sede na Rua das Oliveiras
        numero 14, Lisboa. Contribuinte 501442600. Servico de manutencao anual da instalacao
        eletrica, incluindo substituicao do quadro e verificacao das ligacoes de terra.
        Total a pagar 1.250,50 EUR, com vencimento a trinta dias da data de emissao.
        Pagamento por transferencia bancaria para o IBAN indicado no rodape deste documento.
        TEXT;

    /** @var string A different page entirely, so a second reading of it stops being a duplicate. */
    private const DEED = <<<'TEXT'
        Certidao permanente do registo predial referente ao predio urbano inscrito na matriz sob
        o artigo 3182 da freguesia de Alvalade, concelho de Lisboa. Descricao numero 4471,
        composta por edificio de quatro pisos destinado a habitacao e comercio, confrontando a
        norte com a Avenida do Brasil e a sul com o logradouro comum. Aquisicao registada a
        favor do titular por compra, mediante escritura outorgada no cartorio notarial.
        TEXT;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        config()->set('archivum.ocr.enabled', true);
        config()->set('archivum.ocr.min_text_length', 20);
    }

    public function test_the_button_queues_an_extraction_and_names_the_file_on_the_tasks_page()
    {
        Queue::fake();

        $document = $this->document();
        $attachment = $this->attachment($document, 'scan.pdf', 'application/pdf', ['ocr_status' => OcrStatus::Completed]);

        $this->actingAs($document->creator)
            ->post(route('attachments.extraction.store', $attachment))
            ->assertRedirect();

        Queue::assertPushed(ExtractAttachmentText::class);

        $task = Task::query()->where('type', TaskType::AttachmentTextExtraction)->sole();

        $this->assertSame($document->workspace_id, $task->workspace_id);
        $this->assertSame('scan.pdf', $task->payload['filename']);
        $this->assertSame(
            $attachment->id,
            $task->payload['attachment_id'],
            'A re-extraction must be trackable and retryable from the Tasks page exactly as a first extraction is.',
        );
    }

    public function test_a_second_reading_replaces_the_first_rather_than_being_added_to_it()
    {
        $document = $this->document();
        $attachment = $this->attachment($document, 'scan.png', 'image/png');

        $this->fakeEngine(self::INVOICE);
        $this->extract($attachment);

        $this->assertSame(self::INVOICE, $attachment->refresh()->ocr_text);
        $this->assertNotNull($attachment->text_simhash);

        $this->fakeEngine(self::DEED);
        $this->extract($attachment);

        $attachment->refresh();

        $this->assertSame(self::DEED, $attachment->ocr_text);
        $this->assertStringNotContainsString(
            'Fatura',
            (string) $document->refresh()->ocr_text,
            "The document's mirror must hold the new reading, not the union of both.",
        );
    }

    public function test_a_reading_somebody_had_already_judged_goes_back_on_the_review_queue()
    {
        $document = $this->document();
        $attachment = $this->attachment($document, 'scan.png', 'image/png');

        $this->fakeEngine(self::INVOICE);
        $this->extract($attachment);

        $attachment->confirmOcr();

        $this->assertNotNull($attachment->refresh()->ocr_reviewed_at);

        $this->fakeEngine(self::DEED);
        $this->extract($attachment);

        $this->assertNull(
            $attachment->refresh()->ocr_reviewed_at,
            'The verdict was about text that no longer exists; leaving it would mark a reading nobody has seen as reviewed.',
        );
    }

    public function test_a_duplicate_warning_about_the_old_reading_does_not_survive_the_new_one()
    {
        $document = $this->document();
        $original = $this->attachment($document, 'first.png', 'image/png');
        $copy = $this->attachment($this->document('Second', $document->workspace), 'second.png', 'image/png');

        $this->fakeEngine(self::INVOICE);
        $this->extract($original);
        $this->extract($copy);

        $this->assertSame($original->id, $copy->refresh()->duplicate_of_attachment_id);

        // A better reading of the second file shows it was never the same page.
        $this->fakeEngine(self::DEED);
        $this->extract($copy);

        $this->assertNull(
            $copy->refresh()->duplicate_of_attachment_id,
            'The fingerprint is derived from text that has been replaced, so the match it produced has to be recomputed.',
        );
    }

    public function test_a_file_already_being_read_is_not_queued_twice()
    {
        Queue::fake();

        $document = $this->document();
        $attachment = $this->attachment($document, 'scan.pdf', 'application/pdf', ['ocr_status' => OcrStatus::Processing]);

        $this->actingAs($document->creator)
            ->post(route('attachments.extraction.store', $attachment))
            ->assertSessionHasErrors('attachment');

        Queue::assertNothingPushed();
    }

    public function test_nothing_is_queued_when_extraction_is_switched_off()
    {
        Queue::fake();
        config()->set('archivum.ocr.enabled', false);

        $document = $this->document();
        $attachment = $this->attachment($document, 'scan.pdf', 'application/pdf', ['ocr_status' => OcrStatus::Unavailable]);

        $this->actingAs($document->creator)
            ->post(route('attachments.extraction.store', $attachment))
            ->assertSessionHasErrors('attachment');

        Queue::assertNothingPushed();
    }

    public function test_somebody_outside_the_workspace_cannot_re_read_a_file()
    {
        Queue::fake();

        $document = $this->document();
        $attachment = $this->attachment($document, 'scan.pdf', 'application/pdf');

        $this->actingAs(User::factory()->create())
            ->post(route('attachments.extraction.store', $attachment))
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_a_workspace_sweep_is_one_task_row_and_not_one_per_file()
    {
        Bus::fake();

        $document = $this->document();

        foreach (['a.png', 'b.png', 'c.png'] as $filename) {
            $this->attachment($document, $filename, 'image/png');
        }

        $this->actingAs($document->creator)
            ->post(route('workspaces.tasks.reextract', $document->workspace_id))
            ->assertRedirect();

        $task = Task::query()->sole();

        $this->assertSame(TaskType::BulkAttachmentTextExtraction, $task->type);
        $this->assertSame(3, $task->payload['total']);

        Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->count() === 1
            && $batch->jobs->first() instanceof QueueWorkspaceReextractions);
    }

    public function test_the_sweep_queues_one_extraction_per_attachment_on_the_low_priority_queue()
    {
        $document = $this->document();

        foreach (['a.png', 'b.png'] as $filename) {
            $this->attachment($document, $filename, 'image/png');
        }

        $this->fakeEngine('Alguma coisa legivel');

        [$job, $batch] = (new QueueWorkspaceReextractions(
            $this->sweepTask($document->workspace, $document->creator),
            new ReextractionFilter(),
            null,
            'ocr-bulk',
        ))->withFakeBatch();

        $job->handle();

        $queued = collect($batch->added)->flatten();

        $this->assertCount(2, $queued);
        $this->assertSame(
            'ocr-bulk',
            $queued->first()->queue,
            'A sweep of the whole archive must sit behind ordinary uploads, not in front of them.',
        );
    }

    public function test_a_second_sweep_is_refused_while_one_is_running()
    {
        Bus::fake();

        $document = $this->document();
        $this->attachment($document, 'a.png', 'image/png');

        $this->actingAs($document->creator)
            ->post(route('workspaces.tasks.reextract', $document->workspace_id))
            ->assertRedirect();

        $this->actingAs($document->creator)
            ->post(route('workspaces.tasks.reextract', $document->workspace_id))
            ->assertSessionHasErrors('task');

        $this->assertSame(1, Task::query()->count());
    }

    public function test_a_sweep_over_a_workspace_holding_nothing_is_refused_rather_than_started()
    {
        Bus::fake();

        $document = $this->document();

        $this->actingAs($document->creator)
            ->post(route('workspaces.tasks.reextract', $document->workspace_id))
            ->assertSessionHasErrors('task');

        $this->assertSame(0, Task::query()->count());
        Bus::assertNothingBatched();
    }

    public function test_only_an_admin_may_sweep_the_workspace()
    {
        Bus::fake();

        $document = $this->document();
        $this->attachment($document, 'a.png', 'image/png');

        $member = WorkspaceUser::factory()->for($document->workspace)->create(['role' => WorkspaceRole::User]);

        $this->actingAs($member->user)
            ->post(route('workspaces.tasks.reextract', $document->workspace_id))
            ->assertForbidden();

        Bus::assertNothingBatched();
    }

    public function test_a_sweep_cannot_be_retried_from_the_tasks_page()
    {
        $document = $this->document();

        $task = Task::query()->create([
            'workspace_id' => $document->workspace_id,
            'user_id' => $document->created_by,
            'type' => TaskType::BulkAttachmentTextExtraction,
            'status' => TaskStatus::Failed,
            'result' => ['error' => 'the worker died'],
        ]);

        $this->expectException(ValidationException::class);

        app(RetryTask::class)->handle($task);
    }

    public function test_the_command_reports_what_it_would_do_without_queueing_anything()
    {
        Bus::fake();

        $document = $this->document();
        $this->attachment($document, 'a.png', 'image/png');
        $this->attachment($document, 'b.png', 'image/png');

        $this->artisan('ocr:reextract', ['--dry-run' => true])
            ->expectsOutputToContain('2 attachment(s) would be queued')
            ->assertSuccessful();

        $this->assertSame(0, Task::query()->count());
        Bus::assertNothingBatched();
    }

    public function test_the_command_can_pick_out_only_the_attachments_read_before_confidence_was_recorded()
    {
        Bus::fake();

        $document = $this->document();
        $this->attachment($document, 'old.png', 'image/png', ['ocr_word_count' => null]);
        $this->attachment($document, 'new.png', 'image/png', ['ocr_word_count' => 42]);

        $this->artisan('ocr:reextract', ['--unscored' => true, '--dry-run' => true])
            ->expectsOutputToContain('1 attachment(s) would be queued')
            ->assertSuccessful();
    }

    public function test_the_command_stops_at_the_limit_it_was_given()
    {
        $document = $this->document();

        foreach (['a.png', 'b.png', 'c.png'] as $filename) {
            $this->attachment($document, $filename, 'image/png');
        }

        $this->fakeEngine('Alguma coisa legivel');

        $this->artisan('ocr:reextract', ['--limit' => 2, '--dry-run' => true])
            ->expectsOutputToContain('2 attachment(s) would be queued')
            ->assertSuccessful();

        [$job, $batch] = (new QueueWorkspaceReextractions(
            $this->sweepTask($document->workspace, $document->creator),
            new ReextractionFilter(),
            2,
        ))->withFakeBatch();

        $job->handle();

        $this->assertCount(2, collect($batch->added)->flatten());
    }

    public function test_a_trashed_attachment_is_left_out_of_a_sweep()
    {
        Bus::fake();

        $document = $this->document();
        $this->attachment($document, 'kept.png', 'image/png');
        $this->attachment($document, 'thrown-away.png', 'image/png')->delete();

        $this->actingAs($document->creator)
            ->post(route('workspaces.tasks.reextract', $document->workspace_id))
            ->assertRedirect();

        $this->assertSame(
            1,
            Task::query()->sole()->payload['total'],
            'Re-reading a file on its way to being purged is hours of CPU for text nobody will search.',
        );
    }

    /**
     * The task row a sweep would have created.
     *
     * @param Workspace $workspace The workspace being swept.
     * @param User $user Whose name the sweep is filed under.
     *
     * @return Task The queued bulk task.
     */
    private function sweepTask(Workspace $workspace, User $user): Task
    {
        return Task::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'type' => TaskType::BulkAttachmentTextExtraction,
            'status' => TaskStatus::Queued,
            'payload' => ['total' => 0],
        ]);
    }

    /**
     * Run one extraction over $attachment, as a re-extraction does: no task
     * row, since the sweep owns the only one.
     *
     * @param DocumentAttachment $attachment The attachment to read.
     *
     * @return void No return value; the attachment and its document are updated as a side effect.
     */
    private function extract(DocumentAttachment $attachment): void
    {
        (new ExtractAttachmentText($attachment))->handle(
            app(AttachmentTextExtractor::class),
            app(TextFingerprint::class),
            app(FindDuplicateAttachment::class),
            app(SuggestDocumentMetadata::class),
        );
    }

    /**
     * Text long enough to be fingerprinted for duplicate detection.
     *
     * @param string $seed The distinguishing words.
     *
     * @return string A block of text built around $seed.
     */
    private function longText(string $seed): string
    {
        return mb_trim(str_repeat($seed . ' entre as partes outorgantes para todos os efeitos legais. ', 6));
    }

    /**
     * Create a document with a workspace, an admin creator and a type.
     *
     * @param string $title The document's title.
     * @param Workspace|null $workspace The workspace to file it in; a new one when omitted.
     *
     * @return Document The persisted document, with `creator` loaded.
     */
    private function document(string $title = 'Invoice', ?Workspace $workspace = null): Document
    {
        $workspace ??= Workspace::factory()->create();
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
     * @param array<string, mixed> $attributes Extra columns to force onto the attachment.
     *
     * @return DocumentAttachment The persisted attachment.
     */
    private function attachment(Document $document, string $filename, string $mimeType, array $attributes = []): DocumentAttachment
    {
        $path = 'documents/' . $document->id . '/' . $filename;

        Storage::disk('local')->put($path, 'stored bytes');

        return DocumentAttachment::factory()->for($document)->create([
            'uploaded_by' => $document->created_by ?? User::factory(),
            'disk' => 'local',
            'path' => $path,
            'filename' => $filename,
            'mime_type' => $mimeType,
            ...$attributes,
        ]);
    }

    /**
     * Bind an OCR engine that always recognises $text.
     *
     * @param string $text What the engine returns, taken as fully confident.
     *
     * @return void No return value; binds the engine into the container.
     */
    private function fakeEngine(string $text): void
    {
        $recognized = RecognizedText::confident($text);

        $this->app->instance(OcrEngine::class, new class($recognized) implements OcrEngine
        {
            public function __construct(private readonly RecognizedText $recognized) {}

            public function isAvailable(): bool
            {
                return true;
            }

            public function extract(string $imagePath): RecognizedText
            {
                return $this->recognized;
            }
        });
    }
}
