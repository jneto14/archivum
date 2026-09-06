<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Actions\Documents\CountIntakeReview;
use App\Actions\Documents\CreateDocument;
use App\Actions\Documents\SuggestDocumentMetadata;
use App\Enums\OcrStatus;
use App\Enums\WorkspaceRole;
use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\DocumentType;
use App\Models\IntakeLabel;
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

    public function test_the_queue_can_be_ordered_by_title()
    {
        $workspace = $this->workspace();
        $this->reviewable($workspace, 'Zebra');
        $this->reviewable($workspace, 'Aardvark');

        $this->actingAs($this->member($workspace))
            ->get(route('documents.review', ['workspace' => $workspace, 'sort' => 'title', 'direction' => 'asc']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('sort.key', 'title')
                ->where('documents.0.title', 'Aardvark')
                ->where('documents.1.title', 'Zebra'),
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

    public function test_the_badge_never_points_at_a_row_the_queue_does_not_show()
    {
        $workspace = $this->workspace();
        $document = $this->reviewable($workspace, 'Scan sem titulo');

        // Filled in behind the queue's back — how the real one drifted: a
        // backfill re-read a document whose fields were already complete.
        $document->forceFill([
            'document_date' => '2026-01-05',
            'metadata' => ['amount' => '999,99 EUR'],
        ])->save();

        $this->actingAs($this->member($workspace))
            ->get(route('documents.review', $workspace))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('documents', []));

        $this->assertSame(
            0,
            app(CountIntakeReview::class)->handle($workspace),
            'A badge counting a row the page filters out sends people to an empty queue.',
        );
    }

    public function test_reading_a_document_stores_nothing_for_a_field_already_filled()
    {
        $workspace = $this->workspace();
        $document = $this->reviewable($workspace, 'Scan sem titulo');

        $document->forceFill(['document_date' => '2026-01-05'])->save();

        app(SuggestDocumentMetadata::class)->record($document);

        $kinds = array_column($document->refresh()->metadata_suggestions ?? [], 'kind');

        $this->assertNotContains('document_date', $kinds);
        $this->assertContains('amount', $kinds);
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
        $this->assertSame([], $document->metadata_suggestions, 'A reviewed document must leave the queue.');
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
        $this->assertSame(
            [],
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

    // The engine's confidence says how sure it was of each word, which is not
    // the same question as whether the reading is right — a confident
    // misreading scores as well as a correct one. Only somebody looking at the
    // page can tell them apart, so the text goes in front of them (ARC-118).
    public function test_the_queue_shows_what_was_read_off_each_scan()
    {
        $workspace = $this->workspace();
        $document = $this->reviewable($workspace, 'Fatura da oficina');

        $scan = $this->attachment($document, 'invoice.jpg');
        $scan->markOcrCompleted("Factura 2026/0044\n3  49051 242344062 1165797", 40, 36);

        $this->actingAs($this->member($workspace))
            ->get(route('documents.review', $workspace))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('readings.0.id', $scan->id)
                ->where('readings.0.text', "Factura 2026/0044\n3  49051 242344062 1165797")
                ->where('readings.0.unread_word_count', 4)
                // The one document with suggestions, plus the reading.
                ->where('intakeReviewCount', 2),
            );
    }

    // The queue is for the readings that went badly. A page where every word
    // cleared the floor is not worth anybody's time, and a queue that asks
    // about every upload is one people stop opening.
    public function test_a_reading_the_engine_was_sure_of_is_never_asked_about()
    {
        $workspace = $this->workspace();
        $document = $this->reviewable($workspace, 'Fatura limpa');

        $scan = $this->attachment($document, 'clean.pdf');
        $scan->markOcrCompleted('Factura 2026/0044 total 98,80', 5, 5);

        $this->actingAs($this->member($workspace))
            ->get(route('documents.review', $workspace))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('readings', [])
                // Only the document's own suggestions are waiting.
                ->where('intakeReviewCount', 1),
            );
    }

    public function test_a_page_the_engine_refused_is_listed_with_no_text_to_judge()
    {
        $workspace = $this->workspace();
        $document = $this->reviewable($workspace, 'Recibo manuscrito');

        $scan = $this->attachment($document, 'handwritten.png');
        $scan->markOcrPoorlyRead();

        $this->actingAs($this->member($workspace))
            ->get(route('documents.review', $workspace))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('readings.0.id', $scan->id)
                ->where('readings.0.text', null),
            );
    }

    public function test_confirming_a_reading_keeps_the_text_and_takes_it_off_the_queue()
    {
        $workspace = $this->workspace();
        $document = $this->reviewable($workspace, 'Fatura da oficina');
        $scan = $this->attachment($document, 'invoice.jpg');
        $scan->markOcrCompleted('Factura 2026/0044', 3, 2);

        $this->actingAs($this->member($workspace))
            ->post(route('attachments.reading.confirm', $scan))
            ->assertRedirect();

        $scan->refresh();

        $this->assertNotNull($scan->ocr_reviewed_at);
        $this->assertSame('Factura 2026/0044', $scan->ocr_text);

        $this->actingAs($this->member($workspace))
            ->get(route('documents.review', $workspace))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('readings', []));
    }

    // Refusing deletes the text rather than flagging it. `ocr_text` is what
    // feeds the search index and the duplicate fingerprint, so a reading
    // nobody believes has to stop being one — flagging it would leave it doing
    // its damage.
    public function test_refusing_a_reading_throws_the_text_away()
    {
        $workspace = $this->workspace();
        $document = $this->reviewable($workspace, 'Fatura da oficina');
        $scan = $this->attachment($document, 'invoice.jpg');
        $scan->markOcrCompleted('3  49051 242344062 1165797', 5, 4);
        $scan->recordTextFingerprint(4321, null);
        $document->refreshOcrText();

        $this->actingAs($this->member($workspace))
            ->delete(route('attachments.reading.reject', $scan))
            ->assertRedirect();

        $scan->refresh();

        $this->assertNull($scan->ocr_text);
        $this->assertNull($scan->text_simhash, 'A refused reading must leave the duplicate fingerprint too.');
        $this->assertSame(OcrStatus::PoorlyRead, $scan->ocr_status);
        $this->assertNotNull($scan->ocr_reviewed_at);

        // The document mirrors its attachments, and is what search reads.
        $this->assertNull($document->refresh()->ocr_text);
    }

    public function test_an_outsider_cannot_answer_for_a_reading()
    {
        $workspace = $this->workspace();
        $document = $this->reviewable($workspace, 'Fatura da oficina');
        $scan = $this->attachment($document, 'invoice.jpg');
        $scan->markOcrCompleted('Factura 2026/0044', 3, 2);

        $outsider = WorkspaceUser::factory()->create(['role' => WorkspaceRole::Admin]);

        $this->actingAs($outsider->user)
            ->delete(route('attachments.reading.reject', $scan))
            ->assertForbidden();

        $this->assertSame('Factura 2026/0044', $scan->refresh()->ocr_text);
    }

    /**
     * Create a workspace with one member.
     *
     * @return Workspace The persisted workspace.
     */
    /**
     * Candidate labels belong here rather than in workspace settings, where they
     * were first put. Settings carries no badge and nobody opens it looking for
     * work; this queue is the one screen the sidebar points at.
     */
    public function test_an_admin_is_shown_the_words_the_archive_wants_to_adopt()
    {
        $workspace = $this->workspace();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);

        $label = IntakeLabel::factory()->for($workspace)->create([
            'kind' => 'tax_id',
            'label' => 'steuernummer',
            'support' => 3,
        ]);

        $document = $this->reviewable($workspace, 'Rechnung 2026');
        $label->documents()->attach($document);

        $this->actingAs($admin->user)
            ->get(route('documents.review', $workspace))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('labels', 1)
                ->where('labels.0.label', 'steuernummer')
                ->where('labels.0.field', 'Tax number')
                // The documents that taught it, so the candidate can be judged
                // rather than believed.
                ->where('labels.0.documents.0.title', 'Rechnung 2026'),
            );
    }

    /**
     * A candidate below the threshold has a row — that is where its evidence
     * accumulates — but one document agreeing with itself is not a finding.
     */
    public function test_a_candidate_too_few_documents_agree_on_is_not_shown()
    {
        $workspace = $this->workspace();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);

        IntakeLabel::factory()->for($workspace)->create(['support' => 1]);

        $this->actingAs($admin->user)
            ->get(route('documents.review', $workspace))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('labels', []));
    }

    /**
     * Accepting a word changes how every document in the workspace is read,
     * which is not a member's decision — and a member who cannot answer must
     * not be badged towards it either.
     */
    public function test_a_member_is_shown_no_candidates_and_counted_none()
    {
        $workspace = $this->workspace();

        IntakeLabel::factory()->for($workspace)->create(['support' => 5]);

        $this->actingAs($this->member($workspace))
            ->get(route('documents.review', $workspace))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('labels', []));

        $this->assertSame(0, app(CountIntakeReview::class)->handle($workspace));
        $this->assertSame(1, app(CountIntakeReview::class)->handle($workspace, canAnswerLabels: true));
    }

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
