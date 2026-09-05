<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Enums\WorkspaceRole;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The documents listing had no `ORDER BY` at all.
 *
 * `SearchDocuments` built its query — select, filters, tags, location, eager
 * loads — and called `paginate(15)` without ever ordering it. Scout's database
 * engine has a fallback that would have covered this, but it only applies to a
 * model with no full-text columns, and `Document` declares
 * `#[SearchUsingFullText(['ocr_text'])]`. So the fallback never ran and the rows
 * came back in whatever order the database found convenient.
 *
 * That is not merely untidy. Page two is a fresh query with a different
 * `OFFSET`, and an order that leaves ties is free to resolve them differently
 * each time, so a document can be shown on both pages or on neither while
 * somebody clicks through. The archive appears to lose and duplicate documents
 * and nothing in the code looks wrong.
 */
class DocumentSortingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_listing_comes_back_newest_first_by_default()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);

        Document::factory()->for($workspace)->create(['title' => 'Oldest', 'created_at' => now()->subDays(3)]);
        Document::factory()->for($workspace)->create(['title' => 'Newest', 'created_at' => now()->subDay()]);
        Document::factory()->for($workspace)->create(['title' => 'Middle', 'created_at' => now()->subDays(2)]);

        $this->actingAs($member->user)
            ->get(route('documents.index', $workspace))
            ->assertInertia(fn (Assert $page) => $page
                ->where('documents.data.0.title', 'Newest')
                ->where('documents.data.1.title', 'Middle')
                ->where('documents.data.2.title', 'Oldest'),
            );
    }

    /**
     * Paging a column full of ties, end to end.
     *
     * Every document here shares a document date, so ordering by it alone tells
     * the database nothing about how to arrange them. Note what this can and
     * cannot show: the database is *entitled* to arrange each page differently,
     * not obliged to, and on thirty rows it does not. Removing the tiebreaker
     * leaves this test green — the guarantee itself is pinned against the SQL in
     * `TableSortTest`. This covers the whole path.
     */
    public function test_a_column_full_of_ties_still_pages_without_repeating()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);

        Document::factory()->for($workspace)->count(30)->create(['document_date' => '2026-01-01']);

        $seen = [];

        foreach ([1, 2] as $pageNumber) {
            $this->actingAs($member->user)
                ->get(route('documents.index', [
                    'workspace' => $workspace,
                    'sort' => 'document_date',
                    'direction' => 'asc',
                    'page' => $pageNumber,
                ]))
                ->assertInertia(function (Assert $page) use (&$seen) {
                    /** @var list<array{id: string}> $rows */
                    $rows = $page->toArray()['props']['documents']['data'];

                    $seen = [...$seen, ...array_column($rows, 'id')];
                });
        }

        $this->assertCount(30, $seen);
        $this->assertSame(30, count(array_unique($seen)), 'A document was shown on two pages, or on neither.');
    }

    public function test_the_listing_can_be_sorted_by_title_in_both_directions()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);

        Document::factory()->for($workspace)->create(['title' => 'Beta']);
        Document::factory()->for($workspace)->create(['title' => 'Alpha']);
        Document::factory()->for($workspace)->create(['title' => 'Gamma']);

        $this->actingAs($member->user)
            ->get(route('documents.index', ['workspace' => $workspace, 'sort' => 'title', 'direction' => 'asc']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('documents.data.0.title', 'Alpha')
                ->where('documents.data.2.title', 'Gamma'),
            );

        $this->actingAs($member->user)
            ->get(route('documents.index', ['workspace' => $workspace, 'sort' => 'title', 'direction' => 'desc']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('documents.data.0.title', 'Gamma')
                ->where('documents.data.2.title', 'Alpha'),
            );
    }

    /**
     * Sorting by type means sorting by the type's name, which lives on another
     * table — ordering by the foreign key would sort by an opaque id.
     */
    public function test_sorting_by_type_orders_by_the_type_name()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $invoice = DocumentType::factory()->for($workspace)->create(['name' => 'Invoice']);
        $contract = DocumentType::factory()->for($workspace)->create(['name' => 'Contract']);

        Document::factory()->for($workspace)->for($invoice, 'documentType')->create(['title' => 'An invoice']);
        Document::factory()->for($workspace)->for($contract, 'documentType')->create(['title' => 'A contract']);

        $this->actingAs($member->user)
            ->get(route('documents.index', ['workspace' => $workspace, 'sort' => 'type', 'direction' => 'asc']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('documents.data.0.title', 'A contract')
                ->where('documents.data.1.title', 'An invoice'),
            );
    }

    /**
     * A link to a column that no longer exists should open the page it names,
     * not refuse it. Only the whitelist ever reaches `orderBy`.
     */
    public function test_an_unknown_column_falls_back_to_the_default_order()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);

        Document::factory()->for($workspace)->create(['title' => 'Oldest', 'created_at' => now()->subDays(2)]);
        Document::factory()->for($workspace)->create(['title' => 'Newest', 'created_at' => now()->subDay()]);

        $this->actingAs($member->user)
            ->get(route('documents.index', [
                'workspace' => $workspace,
                'sort' => 'ocr_text); drop table documents;--',
                'direction' => 'sideways',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('sort.key', 'created_at')
                ->where('sort.direction', 'desc')
                ->where('documents.data.0.title', 'Newest'),
            );
    }

    public function test_the_chosen_order_survives_a_page_turn()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);

        Document::factory()->for($workspace)->count(20)->create();

        $this->actingAs($member->user)
            ->get(route('documents.index', ['workspace' => $workspace, 'sort' => 'title', 'direction' => 'desc']))
            ->assertInertia(fn (Assert $page) => $page
                ->where(
                    'documents.links.next',
                    fn (?string $next) => $next !== null
                        && str_contains($next, 'sort=title')
                        && str_contains($next, 'direction=desc'),
                ),
            );
    }
}
