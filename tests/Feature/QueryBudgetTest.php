<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards the per-request query count of the main pages.
 *
 * ARC-85 found a fixed tax of six queries on every request, paid by
 * `ResolveWorkspace` and the Inertia shared props before a page does any work
 * of its own — two thirds of the Tags page. These budgets exist so that tax
 * cannot quietly grow back.
 *
 * Counts when these budgets were set (ARC-85, 30 documents in one workspace):
 * dashboard 12 -> 9, documents index 15 -> 13, tags 9 -> 7, usage 12 -> 9.
 *
 * Each went up by one in ARC-107: the sidebar's review badge is a shared prop,
 * so it is counted on every page. Deliberate — a review queue nobody can see
 * the size of does not get opened — and one query rather than two, which is
 * why `CountIntakeReview` asks for both halves at once.
 *
 * A failure here is not automatically a bug: adding a feature may legitimately
 * add a query. Re-measure, satisfy yourself the new query is necessary, and
 * move the number — deliberately, in the same change.
 */
class QueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Count the queries a single GET costs.
     *
     * @param string $url The URL to request.
     *
     * @return int The number of queries executed while handling the request.
     */
    private function queriesFor(string $url): int
    {
        // Production boots a fresh container per request, so scoped bindings —
        // CalculateWorkspaceUsage's memo among them — start empty. Test requests
        // within one method share a container, so without this the memo leaks
        // across requests and flatters the count.
        $this->app->forgetScopedInstances();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get($url)->assertOk();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * Seed a workspace with an admin and some documents, and act as that admin.
     *
     * @return Workspace The seeded workspace.
     */
    private function seedWorkspace(): Workspace
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $type = DocumentType::factory()->for($workspace)->create();
        Document::factory()->for($workspace)->for($type, 'documentType')->count(30)->create();

        $this->actingAs($admin->user);

        return $workspace;
    }

    public function test_the_dashboard_stays_within_its_query_budget()
    {
        $this->seedWorkspace();

        $this->assertLessThanOrEqual(10, $this->queriesFor(route('dashboard')));
    }

    public function test_the_documents_index_stays_within_its_query_budget()
    {
        $workspace = $this->seedWorkspace();

        $this->assertLessThanOrEqual(14, $this->queriesFor(route('documents.index', $workspace)));
    }

    public function test_the_tags_index_stays_within_its_query_budget()
    {
        $workspace = $this->seedWorkspace();

        $this->assertLessThanOrEqual(8, $this->queriesFor(route('tags.index', $workspace)));
    }

    public function test_the_usage_page_stays_within_its_query_budget()
    {
        $workspace = $this->seedWorkspace();

        $this->assertLessThanOrEqual(10, $this->queriesFor(route('workspaces.usage', $workspace)));
    }

    /**
     * The review queue resolves each row's suggestions against the keys that
     * row's document type uses, which is a query per row unless the lookup is
     * memoised. Five rows and fifteen must therefore cost the same.
     */
    public function test_the_review_queue_does_not_scale_with_row_count()
    {
        $workspace = $this->seedWorkspace();
        $type = DocumentType::factory()->for($workspace)->create();

        $this->seedReviewable($workspace, $type, 5);
        $baseline = $this->queriesFor(route('documents.review', $workspace));

        // Otherwise an empty page would compare equal to an empty page and this
        // would pass with the N+1 still in it.
        $this->assertSame(5, Document::query()->whereNotNull('metadata_suggestions')->count());

        $this->seedReviewable($workspace, $type, 10);

        $this->assertSame($baseline, $this->queriesFor(route('documents.review', $workspace)));
    }

    /**
     * Create documents waiting on the review queue.
     *
     * @param Workspace $workspace The owning workspace.
     * @param DocumentType $type The type they all share.
     * @param int $count How many to create.
     *
     * @return void No return value; persists the documents as a side effect.
     */
    private function seedReviewable(Workspace $workspace, DocumentType $type, int $count): void
    {
        Document::factory()
            ->for($workspace)
            ->for($type, 'documentType')
            ->count($count)
            ->create([
                'ocr_text' => 'Fatura emitida em 20/08/2026, total a pagar 1.250,50 EUR.',
                'document_date' => null,
                'metadata_suggestions' => [
                    ['kind' => 'document_date', 'value' => '2026-08-20'],
                    ['kind' => 'amount', 'value' => '1250.50'],
                ],
            ]);
    }

    /**
     * The page count must not scale with the number of rows on it — that is an
     * N+1, which a flat budget on a fixed fixture would not otherwise catch.
     */
    public function test_the_documents_index_does_not_scale_with_row_count()
    {
        $workspace = $this->seedWorkspace();
        $baseline = $this->queriesFor(route('documents.index', $workspace));

        $type = DocumentType::factory()->for($workspace)->create();
        Document::factory()->for($workspace)->for($type, 'documentType')->count(60)->create();

        $this->assertSame($baseline, $this->queriesFor(route('documents.index', $workspace)));
    }
}
