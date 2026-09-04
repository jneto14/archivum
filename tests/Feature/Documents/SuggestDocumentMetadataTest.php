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
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_a_number_with_no_label_in_front_of_it_is_not_a_tax_id()
    {
        $suggestions = $this->suggestionsFor(
            $this->documentWithText('Encomenda 501442600 registada no sistema em 20/08/2026.'),
        );

        $this->assertArrayNotHasKey(
            'tax_id',
            $suggestions,
            'Nine digits are also order numbers, phone numbers and customer references. The label is the only thing that tells them apart without knowing the country.',
        );
    }

    /**
     * The point of reading by label: none of these are Portuguese, and none of
     * them needed the reader to be told anything about their country.
     *
     * @param string $text A line as a document from that country prints it.
     * @param string $kind The kind it should be read as.
     * @param string $expected The value that should come out.
     */
    #[DataProvider('foreignDocuments')]
    public function test_it_reads_documents_from_other_countries($text, $kind, $expected)
    {
        $suggestions = $this->suggestionsFor($this->documentWithText($text));

        // Says what was read, not just that it was wrong: "null" alone cannot
        // tell a label that failed to match from a value that came out
        // differently, and this line is read on a machine nobody can attach to.
        $this->assertSame(
            $expected,
            $suggestions[$kind] ?? null,
            "Read from \"{$text}\": " . json_encode($suggestions),
        );
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function foreignDocuments(): array
    {
        return [
            // As the page wrote it, spacing and all. Stripping separators was
            // a rule the tax-number reader carried and the plate reader did
            // not, and with the kinds no longer written down in the code there
            // is nobody left to hold an opinion about one kind's punctuation.
            // A user can delete a space; they cannot put back a dash that a
            // policy number needed.
            'a spaced British VAT number' => ['VAT registration 501 234 567', 'tax_id', '501 234 567'],
            'a Spanish VAT number with a letter in it' => ['VAT number ESB12345678', 'tax_id', 'ESB12345678'],
            'an Irish VAT number ending in a letter' => ['VAT no. IE1234567T', 'tax_id', 'IE1234567T'],
            'a British plate' => ['Registration number AB12 CDE', 'vehicle_registration', 'AB12 CDE'],
            'a French plate' => ['Plate AA-123-AA', 'vehicle_registration', 'AA-123-AA'],
            'a German plate' => ['Registration M-AB 1234', 'vehicle_registration', 'M-AB 1234'],
        ];
    }

    public function test_a_label_cannot_reach_across_a_line_break_to_claim_a_value()
    {
        // Folding the page with Str::ascii() in one go replaces every newline
        // with a space, and the whole document becomes a single line — where
        // this label swallowed the invoice number on the line below it and the
        // value came out as "501 234 567 invoice no".
        $suggestions = $this->suggestionsFor($this->documentWithText(<<<'TEXT'
            VAT registration 501 234 567
            INVOICE No. 2026/0184
            TEXT));

        $this->assertSame('501 234 567', $suggestions['tax_id']);
    }

    public function test_a_label_cannot_reach_across_words_to_claim_a_number()
    {
        $suggestions = $this->suggestionsFor(
            $this->documentWithText('Tax number not applicable. Encomenda 998877665.'),
        );

        $this->assertArrayNotHasKey('tax_id', $suggestions);
    }

    public function test_the_ambiguous_date_order_follows_the_configured_one()
    {
        config()->set('archivum.intake.date_order', 'month');

        $suggestions = $this->suggestionsFor($this->documentWithText('Invoice dated 03/04/2026.'));

        $this->assertSame(
            '2026-03-04',
            $suggestions['document_date'],
            'Read month-first, 03/04 is the 4th of March — the one thing about a document that a country still decides.',
        );
    }

    /**
     * A real invoice, as tesseract actually returns one: the date written out
     * in words, the table flattened into a column of bare numbers with the
     * currency nowhere near them, and the tax number spaced into groups.
     *
     * Every one of those defeated the first version of these heuristics, which
     * read precisely nothing from this page.
     */
    public function test_it_reads_an_invoice_as_ocr_actually_returns_one()
    {
        $suggestions = $this->suggestionsFor($this->documentWithText(<<<'TEXT'
            NORTHGATE STATIONERY LTD
            118 Flower Street, Manchester M1 4QB
            VAT registration 501 234 567
            INVOICE No. 2026/0184
            Date: 14 March 2026
            Due: 13 April 2026
            Customer: City Archive
            Description
            A4 archive boxes
            Card dividers
            Adhesive labels
            Subtotal
            VAT at 20%
            Total due
            Qty
            Amount
            40
            184.00
            120
            66.00
            10
            12.50
            262.50
            52.50
            315.00
            TEXT));

        // The issue date, not the due date below it.
        $this->assertSame('2026-03-14', $suggestions['document_date']);
        // The total, not a line item and not the subtotal.
        $this->assertSame('315.00', $suggestions['amount']);
        // Labelled, so the spacing and the country's format do not matter.
        $this->assertSame('501 234 567', $suggestions['tax_id']);
    }

    public function test_a_date_written_in_portuguese_is_read_too()
    {
        $suggestions = $this->suggestionsFor(
            $this->documentWithText('Recibo emitido a 14 de março de 2026 pelos servicos prestados.'),
        );

        $this->assertSame('2026-03-14', $suggestions['document_date']);
    }

    public function test_a_date_is_not_mistaken_for_an_amount()
    {
        $suggestions = $this->suggestionsFor(
            $this->documentWithText('Contrato assinado a 14.03.2026 sob o numero 4471.'),
        );

        $this->assertArrayNotHasKey(
            'amount',
            $suggestions,
            'Without the lookarounds, "14.03.2026" offers up 14.03 as a total.',
        );
        $this->assertSame('2026-03-14', $suggestions['document_date']);
    }

    public function test_a_number_written_without_decimals_is_not_money()
    {
        $suggestions = $this->suggestionsFor(
            $this->documentWithText('Encomenda 4471 de 120 caixas, entregue a 20/08/2026.'),
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
