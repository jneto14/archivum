<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Actions\Documents\CreateDocument;
use App\Actions\Documents\SuggestDocumentMetadata;
use App\Enums\WorkspaceRole;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers what the extracted text is allowed to propose, and — as importantly —
 * what it keeps quiet about: a field the user has already filled, a number that
 * only looks like a tax id, a document with nothing readable in it.
 */
class SuggestDocumentMetadataTest extends TestCase
{
    use RefreshDatabase;

    /** @var string An invoice carrying one of each recognised kind. 501442600 is a valid Portuguese tax number; 501442601 is the same digits with a wrong check digit. */
    private const INVOICE = <<<'TEXT'
        Fatura FT2026/1240 emitida em 20/08/2026 pela Exemplo Lda.
        Contribuinte 501442600. Reparacao do veiculo com a matricula 12-AB-34.
        Subtotal 1.017,48 EUR, IVA 233,02 EUR, total a pagar 1.250,50 EUR.
        TEXT;

    public function test_it_reads_the_date_the_total_the_tax_number_and_the_plate()
    {
        $suggestions = $this->suggestionsFor($this->documentWithText(self::INVOICE));

        $this->assertSame('2026-08-20', $suggestions['document_date']);
        $this->assertSame('501442600', $suggestions['tax_id']);
        $this->assertSame('12-AB-34', $suggestions['vehicle_registration']);
        // The total, not the subtotal or the VAT: an invoice's total is by
        // construction the largest number on it.
        $this->assertSame('1250.50', $suggestions['amount']);
    }

    public function test_an_english_written_amount_is_read_with_the_other_separator()
    {
        $suggestions = $this->suggestionsFor(
            $this->documentWithText('Invoice total EUR 1,250.50 due within thirty days.'),
        );

        $this->assertSame('1250.50', $suggestions['amount']);
    }

    public function test_a_number_that_is_not_a_tax_id_is_not_offered_as_one()
    {
        $suggestions = $this->suggestionsFor(
            $this->documentWithText('Encomenda 501442601 registada no sistema em 20/08/2026.'),
        );

        $this->assertArrayNotHasKey(
            'tax_id',
            $suggestions,
            'Nine digits are also order numbers and customer references; only the check digit tells them apart.',
        );
    }

    public function test_an_amount_without_a_currency_beside_it_is_not_an_amount()
    {
        $suggestions = $this->suggestionsFor(
            $this->documentWithText('Contrato 1.250,50 registado sob o numero 4471 em 20/08/2026.'),
        );

        $this->assertArrayNotHasKey('amount', $suggestions);
    }

    public function test_it_adopts_the_key_the_type_already_uses()
    {
        $workspace = Workspace::factory()->create();
        $type = DocumentType::factory()->for($workspace)->create();

        $this->document($workspace, $type, metadata: ['total' => '80,00 EUR']);
        $this->document($workspace, $type, metadata: ['total' => '95,00 EUR']);

        $suggestions = $this->suggestionsFor(
            $this->documentWithText(self::INVOICE, $workspace, $type),
            'key',
        );

        $this->assertSame(
            'total',
            $suggestions['amount'],
            'A workspace whose invoices all say "total" must not be handed a second field called "amount".',
        );
    }

    public function test_a_key_another_type_uses_is_not_borrowed()
    {
        $workspace = Workspace::factory()->create();
        $invoices = DocumentType::factory()->for($workspace)->create();
        $receipts = DocumentType::factory()->for($workspace)->create();

        $this->document($workspace, $receipts, metadata: ['montante' => '80,00 EUR']);

        $suggestions = $this->suggestionsFor(
            $this->documentWithText(self::INVOICE, $workspace, $invoices),
            'key',
        );

        $this->assertSame('amount', $suggestions['amount']);
    }

    public function test_a_field_that_is_already_filled_is_left_alone()
    {
        $workspace = Workspace::factory()->create();
        $type = DocumentType::factory()->for($workspace)->create();

        $document = $this->documentWithText(self::INVOICE, $workspace, $type);
        $document->forceFill([
            'document_date' => '2026-01-05',
            'metadata' => ['amount' => '999,99 EUR'],
        ])->save();

        $suggestions = $this->suggestionsFor($document);

        $this->assertArrayNotHasKey('document_date', $suggestions);
        $this->assertArrayNotHasKey('amount', $suggestions);
        $this->assertArrayHasKey('tax_id', $suggestions, 'The fields that are still empty must still be offered.');
    }

    public function test_a_document_with_nothing_extracted_suggests_nothing()
    {
        $this->assertSame([], app(SuggestDocumentMetadata::class)->handle($this->documentWithText('')));
    }

    /**
     * Run the suggestions for $document and index them by kind.
     *
     * @param Document $document The document to suggest for.
     * @param string $field Which half of each suggestion to return: its 'value' or its 'key'.
     *
     * @return array<string, string> One entry per kind that produced a suggestion.
     */
    private function suggestionsFor(Document $document, string $field = 'value'): array
    {
        $suggestions = [];

        foreach (app(SuggestDocumentMetadata::class)->handle($document) as $suggestion) {
            $suggestions[$suggestion['kind']] = $suggestion[$field];
        }

        return $suggestions;
    }

    /**
     * Create a document whose attachments have already been read as $text.
     *
     * @param string $text The extracted text to attribute to the document.
     * @param Workspace|null $workspace The owning workspace; created if not given.
     * @param DocumentType|null $type The document's type; created if not given.
     *
     * @return Document The persisted document.
     */
    private function documentWithText(string $text, ?Workspace $workspace = null, ?DocumentType $type = null): Document
    {
        $workspace ??= Workspace::factory()->create();
        $type ??= DocumentType::factory()->for($workspace)->create();

        $document = $this->document($workspace, $type);

        // `ocr_text` is a mirror maintained by extraction, never fillable — see
        // Document::refreshOcrText().
        $document->forceFill(['ocr_text' => $text === '' ? null : $text])->save();

        return $document;
    }

    /**
     * Create a document of $type, with a member of $workspace as its creator.
     *
     * @param Workspace $workspace The owning workspace.
     * @param DocumentType $type The document's type.
     * @param array<string, string>|null $metadata The document's metadata.
     *
     * @return Document The persisted document.
     */
    private function document(Workspace $workspace, DocumentType $type, ?array $metadata = null): Document
    {
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);

        return app(CreateDocument::class)->handle(
            $workspace,
            $member->user,
            $type,
            'Untitled scan',
            null,
            $metadata,
        );
    }
}
