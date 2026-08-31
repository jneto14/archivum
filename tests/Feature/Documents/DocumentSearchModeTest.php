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
 * Covers the two search modes over text extracted from attachments.
 *
 * DatabaseMigrations rather than RefreshDatabase because every assertion here
 * goes through MySQL's full-text index, and InnoDB's FTS cannot see rows
 * written inside an uncommitted transaction.
 */
class DocumentSearchModeTest extends TestCase
{
    use DatabaseMigrations;

    public function test_exact_mode_matches_whole_words_only()
    {
        $workspace = $this->workspaceHolding('Fatura de electricidade, contador 998877');

        $this->assertCount(
            1,
            $this->search($workspace, 'fatura')->items(),
            'A whole word must match in exact mode.',
        );

        $this->assertCount(
            0,
            $this->search($workspace, 'fatur')->items(),
            'Exact mode uses the full-text index in natural language mode, which matches whole words.',
        );
    }

    public function test_broad_mode_matches_the_start_of_a_word()
    {
        $workspace = $this->workspaceHolding('Fatura de electricidade, contador 998877');

        $this->assertCount(
            1,
            $this->search($workspace, 'fatur', SearchMode::Broad)->items(),
            'Broad mode appends a wildcard, so a prefix matches.',
        );
    }

    public function test_broad_mode_does_not_match_the_middle_of_a_word()
    {
        $workspace = $this->workspaceHolding('Fatura de electricidade');

        // The documented cost of staying on the index: a leading wildcard is
        // something MySQL full-text cannot do at all.
        $this->assertCount(0, $this->search($workspace, 'atura', SearchMode::Broad)->items());
    }

    public function test_broad_mode_requires_every_term_to_appear()
    {
        $workspace = $this->workspaceHolding('Fatura de electricidade da EDP');
        Document::factory()->for($workspace)->create([
            'title' => 'Contrato',
            'ocr_text' => 'Fatura de agua',
        ]);

        $this->assertCount(
            1,
            $this->search($workspace, 'fatur edp', SearchMode::Broad)->items(),
            'Terms are ANDed, so a document carrying only one of them must not match.',
        );
    }

    public function test_broad_mode_spans_the_title_and_the_attachment_text()
    {
        $workspace = Workspace::factory()->create();
        Document::factory()->for($workspace)->create([
            'title' => 'Contrato de fornecimento',
            'ocr_text' => 'Potencia contratada 6.9 kVA pela EDP',
        ]);

        $this->assertCount(
            1,
            $this->search($workspace, 'contrat edp', SearchMode::Broad)->items(),
            'One term may come from the title and another from inside a scan.',
        );
    }

    public function test_broad_mode_treats_punctuation_as_a_separator_rather_than_an_operator()
    {
        $workspace = $this->workspaceHolding('Fatura EDP referente a 2026');

        // Unsanitised, boolean mode would read the "-" as NOT and exclude
        // every document containing 2026 — the opposite of what was typed.
        $this->assertCount(
            1,
            $this->search($workspace, 'edp-2026', SearchMode::Broad)->items(),
        );
    }

    public function test_broad_mode_still_finds_terms_the_full_text_index_refuses()
    {
        $workspace = Workspace::factory()->create();
        Document::factory()->for($workspace)->create([
            'title' => 'IA relatorio anual',
            'ocr_text' => 'Sem referencias uteis',
        ]);

        // InnoDB ignores tokens shorter than innodb_ft_min_token_size (3), so
        // the title's LIKE clause is what rescues these.
        $this->assertCount(1, $this->search($workspace, 'IA', SearchMode::Broad)->items());
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
     * Create a workspace holding one document whose attachment text is $text.
     *
     * The title is deliberately unrelated, so every assertion is about the
     * extracted text rather than the title's LIKE clause.
     *
     * @param string $text The document's mirrored attachment text.
     *
     * @return Workspace The workspace holding it.
     */
    private function workspaceHolding(string $text): Workspace
    {
        $workspace = Workspace::factory()->create();

        Document::factory()->for($workspace)->create([
            'title' => 'Untitled scan',
            'ocr_text' => $text,
        ]);

        return $workspace;
    }

    /**
     * Run a search with no structured filters.
     *
     * @param Workspace $workspace The workspace to search in.
     * @param string $query The free-text query.
     * @param SearchMode $mode How the query is matched.
     *
     * @return LengthAwarePaginator<int, Document> The matching documents.
     */
    private function search(Workspace $workspace, string $query, SearchMode $mode = SearchMode::Exact)
    {
        return app(SearchDocuments::class)->handle($workspace, $query, [], $mode);
    }
}
