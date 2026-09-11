<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Actions\Documents\CountIntakeReview;
use App\Actions\Documents\CreateDocument;
use App\Enums\OcrReviewOutcome;
use App\Enums\WorkspaceRole;
use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\DocumentType;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Covers answering the review queue for many documents at once.
 *
 * The queue was built for an archive that fills one upload at a time. A bulk
 * re-extraction fills it thousands of rows at once, at which point answering
 * each on its own is work nobody finishes and the queue stops being opened
 * at all (ARC-127).
 */
class BulkReviewTest extends TestCase
{
    use RefreshDatabase;

    /** @var string Enough of an invoice for the heuristics to find a date and a total. */
    private const INVOICE = 'Fatura FT2026/1240 emitida em 20/08/2026, total a pagar 1.250,50 EUR.';

    public function test_one_row_carries_everything_waiting_on_its_document()
    {
        $workspace = $this->workspace();
        $document = $this->reviewable($workspace, 'Fatura da oficina');

        $badly = $this->attachment($document, 'scan.jpg');
        $badly->markOcrCompleted('Factura 2026/0044', 10, 4);

        $original = $this->attachment($this->reviewable($workspace, 'Original'), 'first.pdf');
        $copy = $this->attachment($document, 'copy.pdf');
        $copy->recordTextFingerprint(1234, $original);

        $rows = $this->rows($this->open($workspace));
        $row = collect($rows)->firstWhere('id', $document->id);

        $this->assertNotNull($row);
        $this->assertCount(2, $row['suggestions']);
        $this->assertCount(1, $row['readings']);
        $this->assertCount(1, $row['duplicates']);
        $this->assertSame(
            4,
            $row['waiting'],
            'A document waiting for three different reasons is one row that says so, not three rows in three sections.',
        );
    }

    public function test_the_queue_can_be_narrowed_to_one_kind_of_finding()
    {
        $workspace = $this->workspace();
        $withSuggestions = $this->reviewable($workspace, 'So sugestoes');
        $withReading = $this->plain($workspace, 'So leitura');

        $scan = $this->attachment($withReading, 'scan.jpg');
        $scan->markOcrCompleted('Factura 2026/0044', 10, 4);

        $this->assertSame(
            [$withReading->id],
            collect($this->rows($this->open($workspace, 'readings')))->pluck('id')->all(),
        );

        $this->assertSame(
            [$withSuggestions->id],
            collect($this->rows($this->open($workspace, 'suggestions')))->pluck('id')->all(),
        );

        $counts = $this->open($workspace)->viewData('page')['props']['counts'];

        $this->assertSame(2, $counts['all']);
        $this->assertSame(1, $counts['suggestions']);
        $this->assertSame(1, $counts['readings']);
        $this->assertSame(0, $counts['duplicates']);
    }

    public function test_accepting_in_bulk_writes_the_values_and_empties_the_queue()
    {
        $workspace = $this->workspace();
        $first = $this->reviewable($workspace, 'Fatura um');
        $second = $this->reviewable($workspace, 'Fatura dois');

        $this->actingAs($this->member($workspace))
            ->post(route('documents.review.bulk', $workspace), [
                'action' => 'accept_suggestions',
                'filter' => 'suggestions',
                'documents' => [$first->id, $second->id],
            ])
            ->assertRedirect();

        foreach ([$first, $second] as $document) {
            $document->refresh();

            $this->assertSame('2026-08-20', $document->document_date?->toDateString());
            $this->assertSame('1250.50', $document->metadata['amount'] ?? null);
            $this->assertSame([], $document->metadata_suggestions);
        }

        $this->assertSame(0, app(CountIntakeReview::class)->handle($workspace));
    }

    public function test_dismissing_readings_in_bulk_never_claims_anybody_read_them()
    {
        $workspace = $this->workspace();
        $document = $this->plain($workspace, 'Fatura da oficina');

        $scan = $this->attachment($document, 'scan.jpg');
        $scan->markOcrCompleted('Factura 2026/0044', 10, 4);

        $this->actingAs($this->member($workspace))
            ->post(route('documents.review.bulk', $workspace), [
                'action' => 'dismiss_readings',
                'filter' => 'readings',
            ])
            ->assertRedirect();

        $scan->refresh();

        $this->assertNotNull($scan->ocr_reviewed_at);
        $this->assertSame(
            OcrReviewOutcome::Dismissed,
            $scan->ocr_review_outcome,
            'Marking a reading as vouched for by somebody who never saw it is the one thing ARC-118 exists to prevent.',
        );
        $this->assertFalse($scan->ocr_review_outcome->isVouchedFor());
        $this->assertSame(
            'Factura 2026/0044',
            $scan->ocr_text,
            'Dismissing takes the question away; refusing is what deletes the text, and stays one at a time.',
        );
        $this->assertSame(0, app(CountIntakeReview::class)->handle($workspace));
    }

    public function test_confirming_one_reading_records_that_somebody_vouched_for_it()
    {
        $workspace = $this->workspace();
        $document = $this->plain($workspace, 'Fatura da oficina');

        $scan = $this->attachment($document, 'scan.jpg');
        $scan->markOcrCompleted('Factura 2026/0044', 10, 4);

        $this->actingAs($this->member($workspace))
            ->post(route('attachments.reading.confirm', $scan))
            ->assertRedirect();

        $this->assertTrue($scan->refresh()->ocr_review_outcome->isVouchedFor());
    }

    public function test_acknowledging_a_page_with_no_text_is_not_recorded_as_vouching_for_it()
    {
        $workspace = $this->workspace();
        $document = $this->plain($workspace, 'Recibo manuscrito');

        $scan = $this->attachment($document, 'handwritten.png');
        $scan->markOcrPoorlyRead(40, 2);

        $this->actingAs($this->member($workspace))
            ->post(route('attachments.reading.confirm', $scan))
            ->assertRedirect();

        $scan->refresh();

        $this->assertSame(OcrReviewOutcome::Acknowledged, $scan->ocr_review_outcome);
        $this->assertFalse(
            $scan->ocr_review_outcome->isVouchedFor(),
            'There was no text to judge, so seeing the row is not the same claim as keeping a reading.',
        );
    }

    public function test_dismissing_duplicates_in_bulk_keeps_both_copies()
    {
        $workspace = $this->workspace();
        $original = $this->attachment($this->plain($workspace, 'Original'), 'first.pdf');
        $copy = $this->attachment($this->plain($workspace, 'Copia'), 'second.pdf');
        $copy->recordTextFingerprint(1234, $original);

        $this->actingAs($this->member($workspace))
            ->post(route('documents.review.bulk', $workspace), [
                'action' => 'dismiss_duplicates',
                'filter' => 'duplicates',
            ])
            ->assertRedirect();

        $this->assertNull($copy->refresh()->duplicate_of_attachment_id);
        $this->assertSame(0, app(CountIntakeReview::class)->handle($workspace));
    }

    public function test_naming_no_documents_answers_for_everything_the_filter_matches()
    {
        $workspace = $this->workspace();

        foreach (range(1, 20) as $n) {
            $this->reviewable($workspace, "Fatura {$n}");
        }

        // More than the fifteen a page shows, so this cannot be passing by
        // only answering for what was on screen.
        $this->assertSame(20, app(CountIntakeReview::class)->handle($workspace));

        $this->actingAs($this->member($workspace))
            ->post(route('documents.review.bulk', $workspace), [
                'action' => 'accept_suggestions',
                'filter' => 'suggestions',
            ])
            ->assertRedirect();

        $this->assertSame(0, app(CountIntakeReview::class)->handle($workspace));
    }

    public function test_the_filter_bounds_what_a_bulk_answer_touches()
    {
        $workspace = $this->workspace();
        $withSuggestions = $this->reviewable($workspace, 'So sugestoes');
        $withReading = $this->plain($workspace, 'So leitura');

        $scan = $this->attachment($withReading, 'scan.jpg');
        $scan->markOcrCompleted('Factura 2026/0044', 10, 4);

        // Answering the readings must not touch the document that only has
        // suggestions, even though "everything matching" was asked for.
        $this->actingAs($this->member($workspace))
            ->post(route('documents.review.bulk', $workspace), [
                'action' => 'dismiss_readings',
                'filter' => 'readings',
            ])
            ->assertRedirect();

        $this->assertNotNull($scan->refresh()->ocr_reviewed_at);
        $this->assertNotSame([], $withSuggestions->refresh()->metadata_suggestions);
    }

    public function test_another_workspaces_queue_is_never_answered_for()
    {
        $workspace = $this->workspace();
        $this->reviewable($workspace, 'Nossa');

        $other = $this->workspace();
        $theirs = $this->reviewable($other, 'Deles');

        $this->actingAs($this->member($workspace))
            ->post(route('documents.review.bulk', $workspace), [
                'action' => 'accept_suggestions',
            ])
            ->assertRedirect();

        $this->assertNotSame(
            [],
            $theirs->refresh()->metadata_suggestions,
            'A bulk answer resolves its own workspace, never every document the filter would match anywhere.',
        );
    }

    public function test_an_outsider_cannot_answer_the_queue_in_bulk()
    {
        $workspace = $this->workspace();
        $document = $this->reviewable($workspace, 'Fatura');

        $this->actingAs(User::factory()->create())
            ->post(route('documents.review.bulk', $workspace), [
                'action' => 'accept_suggestions',
            ])
            ->assertForbidden();

        $this->assertNotSame([], $document->refresh()->metadata_suggestions);
    }

    public function test_an_unknown_action_is_refused()
    {
        $workspace = $this->workspace();
        $document = $this->reviewable($workspace, 'Fatura');

        $this->actingAs($this->member($workspace))
            ->post(route('documents.review.bulk', $workspace), [
                'action' => 'confirm_readings',
            ])
            ->assertSessionHasErrors('action');

        $this->assertNotSame([], $document->refresh()->metadata_suggestions);
    }

    /**
     * @param Workspace $workspace The workspace being reviewed.
     * @param string|null $filter The filter to apply, or null for the default.
     *
     * @return TestResponse The rendered review page.
     */
    private function open(Workspace $workspace, ?string $filter = null): TestResponse
    {
        $url = route('documents.review', $workspace) . ($filter === null ? '' : "?filter={$filter}");

        return $this->actingAs($this->member($workspace))->get($url)->assertOk();
    }

    /**
     * @param TestResponse $response The rendered review page.
     *
     * @return array<int, array<string, mixed>> The document rows.
     */
    private function rows(TestResponse $response): array
    {
        /** @var array<int, array<string, mixed>> $documents */
        $documents = $response->viewData('page')['props']['documents'] ?? [];

        return $documents;
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
     * A document with nothing waiting on it yet.
     *
     * @param Workspace $workspace The owning workspace.
     * @param string $title The document's title.
     *
     * @return Document The persisted document.
     */
    private function plain(Workspace $workspace, string $title): Document
    {
        return app(CreateDocument::class)->handle(
            $workspace,
            $this->member($workspace),
            DocumentType::factory()->for($workspace)->create(),
            $title,
            null,
            null,
        );
    }

    /**
     * A document extraction has already read an invoice out of.
     *
     * @param Workspace $workspace The owning workspace.
     * @param string $title The document's title.
     *
     * @return Document The persisted document, waiting to be reviewed.
     */
    private function reviewable(Workspace $workspace, string $title): Document
    {
        $document = $this->plain($workspace, $title);

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
