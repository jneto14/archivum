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

        $this->assertLessThanOrEqual(9, $this->queriesFor(route('dashboard')));
    }

    public function test_the_documents_index_stays_within_its_query_budget()
    {
        $workspace = $this->seedWorkspace();

        $this->assertLessThanOrEqual(13, $this->queriesFor(route('documents.index', $workspace)));
    }

    public function test_the_tags_index_stays_within_its_query_budget()
    {
        $workspace = $this->seedWorkspace();

        $this->assertLessThanOrEqual(7, $this->queriesFor(route('tags.index', $workspace)));
    }

    public function test_the_usage_page_stays_within_its_query_budget()
    {
        $workspace = $this->seedWorkspace();

        $this->assertLessThanOrEqual(9, $this->queriesFor(route('workspaces.usage', $workspace)));
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
