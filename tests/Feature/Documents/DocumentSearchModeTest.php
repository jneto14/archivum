<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Actions\Documents\SearchDocuments;
use App\Enums\SearchMode;
use App\Enums\WorkspaceRole;
use App\Models\Document;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

/**
 * Covers the four search modes over a document's title and the text extracted
 * from its attachments.
 *
 * DatabaseMigrations rather than RefreshDatabase because every assertion here
 * goes through MySQL's full-text index, and InnoDB's FTS cannot see rows
 * written inside an uncommitted transaction.
 */
class DocumentSearchModeTest extends TestCase
{
    use DatabaseMigrations;

    public function test_all_words_requires_every_term()
    {
        $workspace = Workspace::factory()->create();
        $this->document($workspace, 'Untitled scan', 'Fatura de electricidade da EDP');
        $this->document($workspace, 'Contrato', 'Fatura de agua');

        $this->assertCount(
            1,
            $this->search($workspace, 'fatura edp')->items(),
            'Terms are ANDed, so a document carrying only one of them must not match.',
        );
    }

    public function test_all_words_spans_the_title_and_the_attachment_text()
    {
        $workspace = Workspace::factory()->create();
        $this->document($workspace, 'Contrato de fornecimento', 'Potencia contratada 6.9 kVA pela EDP');

        $this->assertCount(
            1,
            $this->search($workspace, 'contrato edp')->items(),
            'One term may come from the title and another from inside a scan.',
        );
    }

    public function test_all_words_matches_a_multi_word_title_in_any_order()
    {
        $workspace = Workspace::factory()->create();
        $this->document($workspace, 'Contrato de arrendamento', 'Senhorio e arrendatario acordam');

        // The defect ARC-125 fixed: Scout matches a non-full-text column with
        // `LIKE '%<the entire query>%'`, so the "de" sitting between the two
        // words meant neither order matched at all.
        $this->assertCount(1, $this->search($workspace, 'contrato arrendamento')->items());
        $this->assertCount(1, $this->search($workspace, 'arrendamento contrato')->items());
    }

    public function test_all_words_matches_the_start_of_a_word_in_attachment_text()
    {
        $workspace = Workspace::factory()->create();
        $this->document($workspace, 'Untitled scan', 'Fatura de electricidade');

        $this->assertCount(
            1,
            $this->search($workspace, 'fatur')->items(),
            'A trailing wildcard is behaviour now, not a mode of its own.',
        );
    }

    public function test_no_mode_matches_the_middle_of_a_word_in_attachment_text()
    {
        $workspace = Workspace::factory()->create();
        $this->document($workspace, 'Untitled scan', 'Fatura de electricidade');

        // The documented cost of staying on the index: a leading wildcard is
        // something MySQL full-text cannot do at all.
        foreach (SearchMode::cases() as $mode) {
            $this->assertCount(
                0,
                $this->search($workspace, 'atura', $mode)->items(),
                "Mode {$mode->value} must not match the middle of a word.",
            );
        }
    }

    public function test_any_word_matches_a_document_carrying_one_of_them()
    {
        $workspace = Workspace::factory()->create();
        $this->document($workspace, 'Untitled scan', 'Fatura de electricidade');
        $this->document($workspace, 'Outro documento', 'Contrato de arrendamento');

        $this->assertCount(
            2,
            $this->search($workspace, 'fatura contrato', SearchMode::AnyWord)->items(),
            'Either term is enough, so both documents match.',
        );
    }

    public function test_any_word_does_not_leak_past_the_workspace_scoping()
    {
        $workspace = Workspace::factory()->create();
        $this->document($workspace, 'Untitled scan', 'Fatura de electricidade');
        $this->document(Workspace::factory()->create(), 'Fatura de outro workspace', 'Fatura');

        // The disjunction is wrapped in its own group precisely so it cannot
        // OR itself against the workspace clause and return the installation.
        $this->assertCount(1, $this->search($workspace, 'fatura contrato', SearchMode::AnyWord)->items());
    }

    public function test_phrase_requires_the_words_adjacent_and_in_order()
    {
        $workspace = Workspace::factory()->create();
        $this->document($workspace, 'Untitled scan', 'Contrato de arrendamento urbano');

        $this->assertCount(
            1,
            $this->search($workspace, 'arrendamento urbano', SearchMode::Phrase)->items(),
        );

        $this->assertCount(
            0,
            $this->search($workspace, 'urbano arrendamento', SearchMode::Phrase)->items(),
            'Reversing a phrase is a different phrase.',
        );
    }

    public function test_phrase_does_not_match_a_prefix()
    {
        $workspace = Workspace::factory()->create();
        $this->document($workspace, 'Untitled scan', 'Departamento de faturacao mensal');

        $this->assertCount(
            0,
            $this->search($workspace, 'fatura mensal', SearchMode::Phrase)->items(),
            'A phrase that matched prefixes would not be the phrase that was typed.',
        );
    }

    public function test_title_only_ignores_the_attachment_text()
    {
        $workspace = Workspace::factory()->create();
        $this->document($workspace, 'Recibo de agua', 'Fatura mensal do consumo');

        $this->assertCount(
            0,
            $this->search($workspace, 'fatura', SearchMode::TitleOnly)->items(),
            'The scan says "fatura"; the title does not, and the title is all this mode reads.',
        );

        $this->assertCount(1, $this->search($workspace, 'recibo', SearchMode::TitleOnly)->items());
    }

    public function test_title_only_requires_every_term_in_the_title()
    {
        $workspace = Workspace::factory()->create();
        $this->document($workspace, 'Contrato de arrendamento', 'Sem referencias uteis');

        $this->assertCount(1, $this->search($workspace, 'arrendamento contrato', SearchMode::TitleOnly)->items());
        $this->assertCount(0, $this->search($workspace, 'contrato seguro', SearchMode::TitleOnly)->items());
    }

    public function test_every_mode_still_finds_terms_the_full_text_index_refuses()
    {
        $workspace = Workspace::factory()->create();
        $this->document($workspace, 'IA relatorio anual', 'Sem referencias uteis');

        // InnoDB ignores tokens shorter than innodb_ft_min_token_size (3), so
        // the title's LIKE clause is what rescues these.
        foreach (SearchMode::cases() as $mode) {
            $this->assertCount(
                1,
                $this->search($workspace, 'IA', $mode)->items(),
                "Mode {$mode->value} must still match a short term in the title.",
            );
        }
    }

    public function test_punctuation_is_a_separator_rather_than_an_operator()
    {
        $workspace = Workspace::factory()->create();
        $this->document($workspace, 'Untitled scan', 'Fatura EDP referente a 2026');

        // Unsanitised, boolean mode would read the "-" as NOT and exclude
        // every document containing 2026 — the opposite of what was typed.
        $this->assertCount(1, $this->search($workspace, 'edp-2026')->items());
    }

    public function test_a_url_written_before_the_modes_were_renamed_still_works()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);

        // docs/search.md promises a filtered view can be bookmarked and
        // reloaded, so the values this enum used to answer to must resolve
        // rather than come back as a validation error.
        foreach (['exact', 'broad'] as $legacy) {
            $this->actingAs($member->user)
                ->get(route('documents.index', ['workspace' => $workspace, 'mode' => $legacy]))
                ->assertOk()
                ->assertSessionHasNoErrors();
        }
    }

    public function test_an_unknown_mode_is_rejected_rather_than_silently_ignored()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);

        $this->actingAs($member->user)
            ->get(route('documents.index', ['workspace' => $workspace, 'mode' => 'fuzzy']))
            ->assertSessionHasErrors('mode');
    }

    /**
     * Create one document in $workspace.
     *
     * @param Workspace $workspace The workspace to file it in.
     * @param string $title The document's title.
     * @param string $text The document's mirrored attachment text.
     *
     * @return Document The created document.
     */
    private function document(Workspace $workspace, string $title, string $text): Document
    {
        return Document::factory()->for($workspace)->create([
            'title' => $title,
            'ocr_text' => $text,
        ]);
    }

    /**
     * Run a search with no structured filters.
     *
     * @param Workspace $workspace The workspace to search in.
     * @param string $query The free-text query.
     * @param SearchMode|null $mode How the query is matched; the default mode when null.
     *
     * @return LengthAwarePaginator<int, Document> The matching documents.
     */
    private function search(Workspace $workspace, string $query, ?SearchMode $mode = null)
    {
        return app(SearchDocuments::class)->handle($workspace, $query, [], $mode);
    }
}
