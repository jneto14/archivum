<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Actions\Documents\CreateDocument;
use App\Enums\WorkspaceRole;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the one-off pass that exists for installations upgrading into this
 * feature, where every document was extracted before anything was recording
 * what the text contained.
 */
class BackfillMetadataSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_puts_already_extracted_documents_on_the_queue()
    {
        $document = $this->documentWithText('Fatura emitida em 20/08/2026, total a pagar 1.250,50 EUR.');

        $this->assertNull($document->metadata_suggestions);

        $this->artisan('archivum:backfill-suggestions')->assertSuccessful();

        $this->assertCount(2, $document->refresh()->metadata_suggestions ?? []);
    }

    public function test_a_document_whose_text_says_nothing_is_left_off_the_queue()
    {
        $document = $this->documentWithText('Uma pagina em branco, sem nada de util escrito nela.');

        $this->artisan('archivum:backfill-suggestions')->assertSuccessful();

        // Read, and empty: not the same state as never having been read, which
        // is what stops the next run picking it up again.
        $this->assertSame([], $document->refresh()->metadata_suggestions);
    }

    public function test_a_document_already_reviewed_is_left_alone()
    {
        $document = $this->documentWithText('Fatura emitida em 20/08/2026, total a pagar 1.250,50 EUR.');
        $document->recordMetadataSuggestions([]);

        $this->artisan('archivum:backfill-suggestions')->assertSuccessful();

        // The text holds a date and a total, so a second reading would not
        // come back empty — this is only empty because it was skipped.
        $this->assertSame(
            [],
            $document->refresh()->metadata_suggestions,
            'A document somebody has already dealt with must not be put back on the queue.',
        );
    }

    public function test_all_re_reads_everything_for_when_the_heuristics_improve()
    {
        $document = $this->documentWithText('Fatura emitida em 20/08/2026, total a pagar 1.250,50 EUR.');
        $document->recordMetadataSuggestions([]);

        $this->artisan('archivum:backfill-suggestions', ['--all' => true])->assertSuccessful();

        $this->assertCount(2, $document->refresh()->metadata_suggestions ?? []);
    }

    /**
     * Create a document whose attachments have already been read as $text.
     *
     * @param string $text The extracted text to attribute to the document.
     *
     * @return Document The persisted document.
     */
    private function documentWithText(string $text): Document
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);

        $document = app(CreateDocument::class)->handle(
            $workspace,
            $member->user,
            DocumentType::factory()->for($workspace)->create(),
            'Scan',
            null,
            null,
        );

        // `ocr_text` is a mirror maintained by extraction, never fillable.
        $document->forceFill(['ocr_text' => $text])->save();

        return $document;
    }
}
