<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Actions\Documents\LearnIntakeLabels;
use App\Actions\Documents\SuggestDocumentMetadata;
use App\Enums\IntakeLabelStatus;
use App\Enums\WorkspaceRole;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\IntakeLabel;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers what the archive is allowed to teach itself, and — the half that
 * matters more — what it must refuse to.
 *
 * A label that is wrong is not a private mistake: it makes the reader
 * confidently wrong across every document in the workspace that accepted it.
 * So most of what is asserted here is silence.
 */
class LearnIntakeLabelsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Three invoices calling a tax number the same thing, which no language file
     * ships. This is the whole feature: the archive is already holding the
     * answer, in the words its own suppliers print.
     */
    public function test_a_phrase_several_documents_agree_on_is_offered_as_a_label()
    {
        $workspace = Workspace::factory()->create();

        foreach (['501442600', '501442611', '501442622'] as $number) {
            $this->documentSaying(
                $workspace,
                "Rechnung 2026\nSteuernummer {$number}\nBetrag 120,00 EUR",
                ['tax_id' => $number],
            );
        }

        $waiting = app(LearnIntakeLabels::class)->handle($workspace);

        $this->assertSame(1, $waiting);
        $this->assertDatabaseHas('intake_labels', [
            'workspace_id' => $workspace->id,
            'kind' => 'tax_id',
            'label' => 'steuernummer',
            'status' => IntakeLabelStatus::Pending->value,
            'support' => 3,
        ]);
    }

    /**
     * The threshold is what stops one supplier's layout teaching the archive.
     * Two documents agreeing is a coincidence; any page has some word in front
     * of any value.
     *
     * The row exists — evidence has to accumulate somewhere before it can add
     * up to anything — but nothing offers it, and `offered()` is the only way
     * anything reads candidates.
     */
    public function test_a_phrase_only_two_documents_agree_on_is_not_offered()
    {
        $workspace = Workspace::factory()->create();

        foreach (['501442600', '501442611'] as $number) {
            $this->documentSaying(
                $workspace,
                "Steuernummer {$number}",
                ['tax_id' => $number],
            );
        }

        $this->assertSame(0, app(LearnIntakeLabels::class)->handle($workspace));
        $this->assertSame(0, IntakeLabel::query()->offered()->count());
        $this->assertDatabaseHas('intake_labels', ['label' => 'steuernummer', 'support' => 2]);
    }

    /**
     * The page rarely prints a value the way it was typed: a tax number entered
     * as one run of digits is printed spaced, and finding it anyway is what
     * makes this worth running on a real archive rather than a tidy one.
     */
    public function test_a_value_is_found_however_the_page_spaced_it()
    {
        $workspace = Workspace::factory()->create();

        foreach ([['501442600', '501 442 600'], ['501442611', '501.442.611'], ['501442622', '501-442-622']] as [$typed, $printed]) {
            $this->documentSaying($workspace, "Steuernummer {$printed}", ['tax_id' => $typed]);
        }

        app(LearnIntakeLabels::class)->handle($workspace);

        $this->assertDatabaseHas('intake_labels', [
            'kind' => 'tax_id',
            'label' => 'steuernummer',
            'support' => 3,
        ]);
    }

    /**
     * The risk the whole design is arranged around. A connective is in front of
     * a value on every page ever printed, and adopting one would have the reader
     * matching prose.
     */
    public function test_a_word_too_short_to_be_a_label_is_never_offered_alone()
    {
        $workspace = Workspace::factory()->create();

        foreach (['501442600', '501442611', '501442622'] as $number) {
            $this->documentSaying($workspace, "Numero de {$number}", ['tax_id' => $number]);
        }

        app(LearnIntakeLabels::class)->handle($workspace);

        $this->assertDatabaseMissing('intake_labels', ['label' => 'de']);
        $this->assertDatabaseMissing('intake_labels', ['label' => 'numero de']);
    }

    /**
     * Longer phrases are offered beside the short one, because which of them is
     * the label is a judgement only a person reading their own documents can
     * make.
     */
    public function test_the_words_further_back_are_offered_too()
    {
        $workspace = Workspace::factory()->create();

        foreach (['501442600', '501442611', '501442622'] as $number) {
            $this->documentSaying($workspace, "Numero fiscal empresa {$number}", ['tax_id' => $number]);
        }

        app(LearnIntakeLabels::class)->handle($workspace);

        $this->assertDatabaseHas('intake_labels', ['label' => 'empresa']);
        $this->assertDatabaseHas('intake_labels', ['label' => 'fiscal empresa']);
        $this->assertDatabaseHas('intake_labels', ['label' => 'numero fiscal empresa']);
    }

    /**
     * A word the language files already carry is not news, and offering it would
     * ask an admin to approve what is already in use.
     */
    public function test_a_label_the_reader_already_knows_is_not_offered_again()
    {
        $workspace = Workspace::factory()->create();

        foreach (['501442600', '501442611', '501442622'] as $number) {
            $this->documentSaying($workspace, "VAT number {$number}", ['tax_id' => $number]);
        }

        app(LearnIntakeLabels::class)->handle($workspace);

        $this->assertDatabaseMissing('intake_labels', ['label' => 'vat number']);
    }

    /**
     * Without this, an admin would turn down the same word every time the job
     * runs, for the life of the archive.
     */
    public function test_a_rejected_phrase_is_not_asked_about_again()
    {
        $workspace = Workspace::factory()->create();

        IntakeLabel::factory()->rejected()->create([
            'workspace_id' => $workspace->id,
            'kind' => 'tax_id',
            'label' => 'steuernummer',
        ]);

        foreach (['501442600', '501442611', '501442622'] as $number) {
            $this->documentSaying($workspace, "Steuernummer {$number}", ['tax_id' => $number]);
        }

        $this->assertSame(0, app(LearnIntakeLabels::class)->handle($workspace));
        $this->assertDatabaseHas('intake_labels', [
            'label' => 'steuernummer',
            'status' => IntakeLabelStatus::Rejected->value,
            // The evidence is refreshed even so, so an old decision can be
            // reconsidered against what the archive says now.
            'support' => 3,
        ]);
    }

    /**
     * The point of the whole exercise: an accepted phrase joins the vocabulary
     * the reader uses, beside the ones that shipped.
     */
    public function test_an_accepted_label_is_read_with_and_stays_in_its_workspace()
    {
        $workspace = Workspace::factory()->create();
        $other = Workspace::factory()->create();

        IntakeLabel::factory()->accepted()->create([
            'workspace_id' => $workspace->id,
            'kind' => 'tax_id',
            'label' => 'steuernummer',
        ]);

        $text = "Rechnung 2026\nSteuernummer 501442600\nBetrag 120,00 EUR";
        $suggest = app(SuggestDocumentMetadata::class);

        $taught = collect($suggest->extract($text, $workspace->id))->firstWhere('kind', 'tax_id');
        $untaught = collect($suggest->extract($text, $other->id))->firstWhere('kind', 'tax_id');

        $this->assertSame('501442600', $taught['value'] ?? null);
        $this->assertNull($untaught, 'A label one workspace accepted must not be read into another.');
    }

    /**
     * Only the kinds the reader recognises by their words can be taught any. An
     * amount is found by its shape, so vocabulary for it would sit unread —
     * and the words in front of an amount are every heading on every invoice.
     */
    public function test_nothing_is_learned_for_a_kind_read_by_its_shape()
    {
        $workspace = Workspace::factory()->create();

        foreach (['1250.50', '1300.50', '1400.50'] as $amount) {
            $this->documentSaying($workspace, "Valor final {$amount}", ['amount' => $amount]);
        }

        $this->assertSame(0, app(LearnIntakeLabels::class)->handle($workspace));
        $this->assertDatabaseCount('intake_labels', 0);
    }

    /**
     * Mining reads a workspace, not an installation, so a phrase is only ever
     * evidenced by documents that could reasonably share a vocabulary.
     */
    public function test_documents_in_another_workspace_do_not_count_towards_a_candidate()
    {
        $workspace = Workspace::factory()->create();
        $other = Workspace::factory()->create();

        $this->documentSaying($workspace, 'Steuernummer 501442600', ['tax_id' => '501442600']);

        foreach (['501442611', '501442622'] as $number) {
            $this->documentSaying($other, "Steuernummer {$number}", ['tax_id' => $number]);
        }

        $this->assertSame(0, app(LearnIntakeLabels::class)->handle($workspace));
    }

    /**
     * A document whose filed value is nowhere in its text says nothing about
     * what the page calls things — the user typed it from somewhere else.
     */
    public function test_a_value_that_is_not_in_the_text_teaches_nothing()
    {
        $workspace = Workspace::factory()->create();

        foreach (['501442600', '501442611', '501442622'] as $number) {
            $this->documentSaying($workspace, 'Steuernummer nao consta desta pagina', ['tax_id' => $number]);
        }

        $this->assertSame(0, app(LearnIntakeLabels::class)->handle($workspace));
    }

    /**
     * The point of the kinds not being written down anywhere. Nobody shipped a
     * policy number, nobody could have — and the archive learns to read one
     * with no more ceremony than the tax number it was shipped knowing about.
     */
    public function test_a_field_nobody_shipped_is_learned_like_any_other()
    {
        $workspace = Workspace::factory()->create();

        foreach (['AP4471182', 'AP4471183', 'AP4471184'] as $policy) {
            $this->documentSaying(
                $workspace,
                "Seguradora Exemplo\nApolice {$policy}\nPremio 120,00 EUR",
                ['Nº Apólice' => $policy],
            );
        }

        $this->assertSame(1, app(LearnIntakeLabels::class)->handle($workspace));
        $this->assertDatabaseHas('intake_labels', [
            'workspace_id' => $workspace->id,
            // The metadata key, normalised. There is no enum this had to be in.
            'kind' => 'no_apolice',
            'label' => 'apolice',
            'support' => 3,
        ]);
    }

    /**
     * The consistency filter. A field somebody types sentences into has no
     * shape to check a reading against, so learning for it would teach the
     * reader to lift prose off pages — the failure this whole design is
     * arranged to avoid.
     */
    public function test_a_free_text_field_teaches_nothing()
    {
        $workspace = Workspace::factory()->create();

        $notes = [
            'Entregue em mao no balcao 3 durante a manha',
            'Aguarda parecer juridico 2026',
            'Substitui o documento anterior de 2024 por engano',
        ];

        foreach ($notes as $note) {
            $this->documentSaying($workspace, "Observacoes {$note}", ['Observações' => $note]);
        }

        $this->assertSame(0, app(LearnIntakeLabels::class)->handle($workspace));
        $this->assertDatabaseCount('intake_labels', 0);
    }

    /**
     * The reason the evidence is rows rather than a counter. Learning is
     * incremental now — a document is read again every time it is saved — and a
     * count that is added to would climb every time somebody edited the same
     * document, until one page cleared a threshold meant to require several.
     */
    public function test_reading_the_same_document_again_does_not_count_it_twice()
    {
        $workspace = Workspace::factory()->create();
        $document = $this->documentSaying($workspace, 'Steuernummer 501442600', ['tax_id' => '501442600']);

        $learn = app(LearnIntakeLabels::class);

        $learn->learn($document);
        $learn->learn($document);
        $learn->learn($document);

        $this->assertDatabaseHas('intake_labels', ['label' => 'steuernummer', 'support' => 1]);
    }

    /**
     * A value corrected to a different number stops being evidence for whatever
     * introduced the old one. Without replacing the document's evidence, a typo
     * would keep voting for the phrase it was mistakenly found next to.
     */
    public function test_correcting_a_value_withdraws_what_it_taught()
    {
        $workspace = Workspace::factory()->create();

        $document = $this->documentSaying(
            $workspace,
            "Steuernummer 501442600\nBestellnummer 998877665",
            ['tax_id' => '501442600'],
        );

        $learn = app(LearnIntakeLabels::class);
        $learn->learn($document);

        $this->assertDatabaseHas('intake_labels', ['label' => 'steuernummer']);

        $document->update(['metadata' => ['tax_id' => '998877665']]);
        $learn->learn($document);

        $this->assertDatabaseMissing('intake_labels', ['label' => 'steuernummer']);
        $this->assertDatabaseHas('intake_labels', ['label' => 'bestellnummer', 'support' => 1]);
    }

    /**
     * Which documents said so, not just how many. It is what makes the count
     * idempotent, and what an admin is shown so a candidate can be judged
     * rather than believed.
     */
    public function test_the_documents_that_taught_a_phrase_are_recorded()
    {
        $workspace = Workspace::factory()->create();

        $documents = collect(['501442600', '501442611', '501442622'])
            ->map(fn (string $number): Document => $this->documentSaying(
                $workspace,
                "Steuernummer {$number}",
                ['tax_id' => $number],
            ));

        app(LearnIntakeLabels::class)->handle($workspace);

        $label = IntakeLabel::query()->where('label', 'steuernummer')->sole();

        $this->assertEqualsCanonicalizing(
            $documents->pluck('id')->all(),
            $label->documents->pluck('id')->all(),
        );
    }

    /**
     * Create a document in $workspace whose text says $text and whose metadata
     * holds $metadata.
     *
     * @param Workspace $workspace The owning workspace.
     * @param string $text What the page was read as saying.
     * @param array<string, string> $metadata What somebody filled in on it.
     *
     * @return Document The persisted document.
     */
    private function documentSaying(Workspace $workspace, string $text, array $metadata): Document
    {
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $type = DocumentType::factory()->for($workspace)->create();

        $document = Document::factory()->for($workspace)->create([
            'document_type_id' => $type->id,
            'created_by' => $member->user_id,
            'metadata' => $metadata,
        ]);

        // `ocr_text` is a mirror maintained by extraction, never fillable.
        $document->forceFill(['ocr_text' => $text])->save();

        return $document;
    }
}
