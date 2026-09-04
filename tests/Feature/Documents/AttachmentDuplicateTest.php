<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Actions\Documents\CreateDocument;
use App\Actions\Documents\FindDuplicateAttachment;
use App\Actions\Documents\SuggestDocumentMetadata;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Enums\WorkspaceRole;
use App\Jobs\ExtractAttachmentText;
use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\DocumentType;
use App\Models\Task;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Ocr\AttachmentTextExtractor;
use App\Services\Ocr\Contracts\OcrEngine;
use App\Services\Ocr\TextFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Covers what extraction concludes about an attachment beyond its text: which
 * earlier attachment it is a copy of, where that stops (another workspace, the
 * same document), and how a user gets rid of the warning.
 */
class AttachmentDuplicateTest extends TestCase
{
    use RefreshDatabase;

    /** @var string A page's worth of text. Short fixtures are deliberately not fingerprinted at all — see TextFingerprintTest. */
    private const INVOICE = <<<'TEXT'
        Fatura FT2026/1240 emitida em 20/08/2026 pela Exemplo Lda, com sede na Rua das Oliveiras
        numero 14, Lisboa. Contribuinte 501442600. Servico de manutencao anual da instalacao
        eletrica, incluindo substituicao do quadro e verificacao das ligacoes de terra.
        Total a pagar 1.250,50 EUR, com vencimento a trinta dias da data de emissao.
        Pagamento por transferencia bancaria para o IBAN indicado no rodape deste documento.
        TEXT;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        config()->set('archivum.ocr.enabled', true);
    }

    public function test_the_same_page_filed_twice_is_flagged_against_the_first_copy()
    {
        $workspace = $this->workspace();
        $original = $this->extracted($this->document($workspace, 'Manutencao agosto'), self::INVOICE);

        // Photographed a second time: the same page, read slightly differently.
        $copy = $this->extracted(
            $this->document($workspace, 'Scan sem titulo'),
            str_replace(['Oliveiras', 'quadro'], ['Ollveiras', 'quaclro'], self::INVOICE),
        );

        $this->assertSame($original->id, $copy->refresh()->duplicate_of_attachment_id);
        $this->assertNull(
            $original->refresh()->duplicate_of_attachment_id,
            'The copy points at the original, never the other way round: the first one filed was not a duplicate of anything.',
        );
    }

    public function test_an_unrelated_document_is_not_flagged()
    {
        $workspace = $this->workspace();
        $this->extracted($this->document($workspace, 'Fatura'), self::INVOICE);

        $other = $this->extracted($this->document($workspace, 'Apolice'), <<<'TEXT'
            Apolice de seguro automovel numero 88213394 celebrada com a Seguradora Exemplo SA
            para o veiculo com a matricula 12-AB-34, marca Renault, modelo Clio, do ano de 2019.
            Cobertura de responsabilidade civil obrigatoria, protecao juridica e assistencia em
            viagem. Premio anual de 340,00 EUR, pago em duas prestacoes semestrais, com inicio
            de vigencia a 01/03/2026 e renovacao automatica salvo denuncia com trinta dias.
            TEXT);

        $this->assertNull($other->refresh()->duplicate_of_attachment_id);
    }

    public function test_an_identical_page_in_another_workspace_is_never_reported()
    {
        $this->extracted($this->document($this->workspace(), 'Fatura'), self::INVOICE);

        $elsewhere = $this->extracted($this->document($this->workspace(), 'Fatura'), self::INVOICE);

        $this->assertNull(
            $elsewhere->refresh()->duplicate_of_attachment_id,
            'Naming a document from another workspace would leak it to somebody with no access to it.',
        );
    }

    public function test_two_pages_of_one_document_do_not_flag_each_other()
    {
        $document = $this->document($this->workspace(), 'Contrato');

        $this->extracted($document, self::INVOICE, 'frente.png');
        $back = $this->extracted($document, self::INVOICE, 'verso.png');

        $this->assertNull(
            $back->refresh()->duplicate_of_attachment_id,
            'A document duplicating itself is noise: two near-identical pages of one record are routine.',
        );
    }

    public function test_dismissing_the_warning_keeps_it_gone()
    {
        $workspace = $this->workspace();
        $this->extracted($this->document($workspace, 'Manutencao agosto'), self::INVOICE);
        $copy = $this->extracted($this->document($workspace, 'Scan sem titulo'), self::INVOICE);

        $this->actingAs($workspace->users()->first())
            ->delete(route('attachments.duplicate.dismiss', $copy))
            ->assertRedirect();

        $this->assertNull(
            $copy->refresh()->duplicate_of_attachment_id,
            'A warning that comes back on the next page load has not been dismissed.',
        );
    }

    public function test_an_outsider_cannot_dismiss_a_warning()
    {
        $workspace = $this->workspace();
        $this->extracted($this->document($workspace, 'Manutencao agosto'), self::INVOICE);
        $copy = $this->extracted($this->document($workspace, 'Scan sem titulo'), self::INVOICE);

        $outsider = WorkspaceUser::factory()->create(['role' => WorkspaceRole::Admin]);

        $this->actingAs($outsider->user)
            ->delete(route('attachments.duplicate.dismiss', $copy))
            ->assertForbidden();

        $this->assertNotNull($copy->refresh()->duplicate_of_attachment_id);
    }

    public function test_deleting_the_original_takes_the_warning_with_it()
    {
        $workspace = $this->workspace();
        $original = $this->extracted($this->document($workspace, 'Manutencao agosto'), self::INVOICE);
        $copy = $this->extracted($this->document($workspace, 'Scan sem titulo'), self::INVOICE);

        $original->delete();

        $this->assertNull(
            $copy->refresh()->duplicate_of_attachment_id,
            'A warning pointing at a file that no longer exists has nothing to show the user.',
        );
    }

    public function test_the_finder_leaves_the_attachment_alone_when_it_has_no_document()
    {
        $orphan = new DocumentAttachment();

        $this->assertNull(app(FindDuplicateAttachment::class)->handle($orphan, 1234));
    }

    /**
     * Create a workspace with one admin member.
     *
     * @return Workspace The persisted workspace.
     */
    private function workspace(): Workspace
    {
        $workspace = Workspace::factory()->create();

        WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);

        return $workspace;
    }

    /**
     * Create a document in $workspace, attributed to its first member.
     *
     * @param Workspace $workspace The owning workspace.
     * @param string $title The document's title.
     *
     * @return Document The persisted document.
     */
    private function document(Workspace $workspace, string $title): Document
    {
        return app(CreateDocument::class)->handle(
            $workspace,
            $workspace->users()->firstOrFail(),
            DocumentType::factory()->for($workspace)->create(),
            $title,
            null,
            null,
        );
    }

    /**
     * Attach a file to $document and run the extraction job over it, with an
     * engine that recognises exactly $text.
     *
     * @param Document $document The owning document.
     * @param string $text What extraction returns for this file.
     * @param string $filename The stored filename.
     *
     * @return DocumentAttachment The extracted attachment.
     */
    private function extracted(Document $document, string $text, string $filename = 'scan.png'): DocumentAttachment
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

        $path = 'documents/' . $document->id . '/' . $filename;
        Storage::disk('local')->put($path, 'stored bytes');

        $attachment = DocumentAttachment::factory()->for($document)->create([
            'uploaded_by' => $document->created_by,
            'disk' => 'local',
            'path' => $path,
            'filename' => $filename,
            'mime_type' => 'image/png',
        ]);

        $task = Task::query()->create([
            'workspace_id' => $document->workspace_id,
            'user_id' => $document->created_by,
            'type' => TaskType::AttachmentTextExtraction,
            'status' => TaskStatus::Queued,
            'payload' => ['attachment_id' => $attachment->id],
        ]);

        (new ExtractAttachmentText($attachment, $task))->handle(
            app(AttachmentTextExtractor::class),
            app(TextFingerprint::class),
            app(FindDuplicateAttachment::class),
            app(SuggestDocumentMetadata::class),
        );

        return $attachment;
    }
}
