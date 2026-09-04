<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Actions\Documents\CreateDocument;
use App\Enums\WorkspaceRole;
use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\DocumentType;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Covers the queue that exists because extraction finishes long after whoever
 * filed the document has moved on: what it lists, what it stops listing, and
 * what accepting from it writes.
 */
class IntakeReviewTest extends TestCase
{
    use RefreshDatabase;

    /** @var string Enough of an invoice for the heuristics to find a date and a total. */
    private const INVOICE = 'Fatura FT2026/1240 emitida em 20/08/2026, total a pagar 1.250,50 EUR.';

    public function test_it_lists_documents_whose_scan_had_something_to_say()
    {
        $workspace = $this->workspace();
        $document = $this->reviewable($workspace, 'Scan sem titulo');

        $this->actingAs($this->member($workspace))
            ->get(route('documents.review', $workspace))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('documents/review')
                ->where('documents.0.id', $document->id)
                ->where('documents.0.suggestions.0.kind', 'document_date')
                ->where('documents.0.suggestions.1.kind', 'amount'),
            );
    }

    public function test_another_workspaces_documents_are_never_listed()
    {
        $workspace = $this->workspace();
        $this->reviewable($this->workspace(), 'Elsewhere');

        $this->actingAs($this->member($workspace))
            ->get(route('documents.review', $workspace))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('documents', []));
    }

    public function test_a_document_whose_fields_were_filled_in_by_hand_is_not_listed()
    {
        $workspace = $this->workspace();
        $document = $this->reviewable($workspace, 'Scan sem titulo');

        $document->forceFill([
            'document_date' => '2026-01-05',
            'metadata' => ['amount' => '999,99 EUR'],
        ])->save();

        // The findings are still stored — they are cleared on the document's
        // next edit — so the page itself has to leave it out rather than list a
        // row with nothing in it.
        $this->actingAs($this->member($workspace))
            ->get(route('documents.review', $workspace))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('documents', []));
    }

    public function test_an_outsider_cannot_open_the_queue()
    {
        $workspace = $this->workspace();
        $outsider = WorkspaceUser::factory()->create(['role' => WorkspaceRole::Admin]);

        $this->actingAs($outsider->user)
            ->get(route('documents.review', $workspace))
            ->assertForbidden();
    }

    public function test_accepting_writes_the_values_and_clears_the_document()
    {
        $workspace = $this->workspace();
        $document = $this->reviewable($workspace, 'Scan sem titulo');

        $this->actingAs($this->member($workspace))
            ->post(route('documents.suggestions.accept', $document), [
                'kinds' => ['document_date', 'amount'],
            ])
            ->assertRedirect();

        $document->refresh();

        $this->assertSame('2026-08-20', $document->document_date?->toDateString());
        $this->assertSame('1250.50', $document->metadata['amount'] ?? null);
        $this->assertNull($document->metadata_suggestions, 'A reviewed document must leave the queue.');
    }

    public function test_accepting_one_kind_leaves_the_others_unwritten()
    {
        $workspace = $this->workspace();
        $document = $this->reviewable($workspace, 'Scan sem titulo');

        $this->actingAs($this->member($workspace))
            ->post(route('documents.suggestions.accept', $document), ['kinds' => ['amount']])
            ->assertRedirect();

        $document->refresh();

        $this->assertSame('1250.50', $document->metadata['amount'] ?? null);
        $this->assertNull($document->document_date, 'Only the kinds named may be written.');
    }

    public function test_none_of_these_takes_the_document_off_the_queue_without_writing_anything()
    {
        $workspace = $this->workspace();
        $document = $this->reviewable($workspace, 'Scan sem titulo');

        $this->actingAs($this->member($workspace))
            ->post(route('documents.suggestions.accept', $document), ['kinds' => []])
            ->assertRedirect();

        $document->refresh();

        $this->assertNull($document->document_date);
        $this->assertNull($document->metadata);
        $this->assertNull(
            $document->metadata_suggestions,
            '"Nothing here" is an answer, and a row that comes back tomorrow has not accepted it.',
        );
    }

    public function test_the_client_cannot_smuggle_a_value_through_the_accept_route()
    {
        $workspace = $this->workspace();
        $document = $this->reviewable($workspace, 'Scan sem titulo');

        $this->actingAs($this->member($workspace))
            ->post(route('documents.suggestions.accept', $document), [
                'kinds' => ['amount'],
                'metadata' => ['amount' => 'whatever I like'],
            ])
            ->assertRedirect();

        $this->assertSame(
            '1250.50',
            $document->refresh()->metadata['amount'] ?? null,
            'Only the kinds travel; the values are looked up again on the server.',
        );
    }

    public function test_an_outsider_cannot_accept_suggestions()
    {
        $workspace = $this->workspace();
        $document = $this->reviewable($workspace, 'Scan sem titulo');
        $outsider = WorkspaceUser::factory()->create(['role' => WorkspaceRole::Admin]);

        $this->actingAs($outsider->user)
            ->post(route('documents.suggestions.accept', $document), ['kinds' => ['amount']])
            ->assertForbidden();

        $this->assertNotNull($document->refresh()->metadata_suggestions);
    }

    public function test_the_queue_lists_flagged_duplicates_and_the_sidebar_counts_both()
    {
        $workspace = $this->workspace();
        $original = $this->reviewable($workspace, 'Manutencao agosto');
        $copy = $this->reviewable($workspace, 'Scan sem titulo');

        $filed = $this->attachment($original, 'original.pdf');
        $duplicate = $this->attachment($copy, 'copy.pdf');
        $duplicate->recordTextFingerprint(1234, $filed);

        $this->actingAs($this->member($workspace))
            ->get(route('documents.review', $workspace))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('duplicates.0.id', $duplicate->id)
                ->where('duplicates.0.duplicate_of.document_title', 'Manutencao agosto')
                // Two documents with suggestions, plus the flagged attachment.
                ->where('intakeReviewCount', 3),
            );
    }

    /**
     * Create a workspace with one member.
     *
     * @return Workspace The persisted workspace.
     */
    private function workspace(): Workspace
    {
        $workspace = Workspace::factory()->create();

        WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);

        return $workspace;
    }

    /**
     * @param Workspace $workspace The workspace whose member is wanted.
     *
     * @return User The workspace's first member.
     */
    private function member(Workspace $workspace): User
    {
        return $workspace->users()->firstOrFail();
    }

    /**
     * Create a document that extraction has already read an invoice out of.
     *
     * @param Workspace $workspace The owning workspace.
     * @param string $title The document's title.
     *
     * @return Document The persisted document, waiting to be reviewed.
     */
    private function reviewable(Workspace $workspace, string $title): Document
    {
        $document = app(CreateDocument::class)->handle(
            $workspace,
            $this->member($workspace),
            DocumentType::factory()->for($workspace)->create(),
            $title,
            null,
            null,
        );

        // Both columns are mirrors maintained by extraction, never fillable.
        $document->forceFill(['ocr_text' => self::INVOICE])->save();
        $document->recordMetadataSuggestions([
            ['kind' => 'document_date', 'value' => '2026-08-20'],
            ['kind' => 'amount', 'value' => '1250.50'],
        ]);

        return $document;
    }

    /**
     * @param Document $document The owning document.
     * @param string $filename The attachment's filename.
     *
     * @return DocumentAttachment The persisted attachment.
     */
    private function attachment(Document $document, string $filename): DocumentAttachment
    {
        return DocumentAttachment::factory()->for($document)->create([
            'uploaded_by' => $document->created_by,
            'filename' => $filename,
        ]);
    }
}
