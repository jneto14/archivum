<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Enums\WorkspaceRole;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards the per-request query count of the API's listings, the way
 * QueryBudgetTest does for the pages.
 *
 * These should be cheaper than the equivalent page and the budgets say so:
 * there is no ResolveWorkspace resolving a session's workspace and no Inertia
 * shared props, which together were the fixed six-query tax ARC-85 found. What
 * is left is the listing itself, so an N+1 introduced in a resource has
 * nowhere to hide.
 *
 * A failure here is not automatically a bug — adding a field may legitimately
 * add a query. Re-measure, satisfy yourself it is necessary, and move the
 * number deliberately, in the same change.
 */
class ApiQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($this->workspace)->create(['role' => WorkspaceRole::Admin]);
        $type = DocumentType::factory()->for($this->workspace)->create();
        Document::factory()->for($this->workspace)->for($type, 'documentType')->count(30)->create();

        $this->token = $member->user->createToken('CLI')->plainTextToken;
    }

    /**
     * Count the queries a single authenticated GET costs.
     *
     * @param string $url The URL to request.
     *
     * @return int The number of queries executed while handling the request.
     */
    private function queriesFor(string $url): int
    {
        // Production boots a fresh container per request, so scoped bindings
        // start empty. Test requests within one method share a container.
        $this->app->forgetScopedInstances();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->withToken($this->token)->getJson($url)->assertOk();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * Eleven, and what they are: three for the token (find it, load its user,
     * stamp `last_used_at`), two for the workspace and the membership the
     * policy checks, two for the count and the page, and four eager loads —
     * type, tags, current location, creator.
     */
    public function test_the_document_listing_stays_within_its_query_budget()
    {
        $this->assertLessThanOrEqual(
            11,
            $this->queriesFor("/api/v1/workspaces/{$this->workspace->id}/documents"),
        );
    }

    public function test_searching_stays_within_the_same_budget_as_listing()
    {
        $this->assertLessThanOrEqual(
            11,
            $this->queriesFor("/api/v1/workspaces/{$this->workspace->id}/documents?q=invoice"),
        );
    }

    /**
     * Thirty rows cost what fifteen do, or something is being loaded per row.
     *
     * Both measurements are taken warm. The guard keeps the resolved user
     * between requests in one test method, where production resolves the token
     * afresh every time, so a cold first call is three queries dearer than a
     * warm second one and comparing the two would measure that instead.
     */
    public function test_a_larger_page_does_not_cost_more_queries()
    {
        $this->withToken($this->token)
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/documents")
            ->assertOk();

        $fifteen = $this->queriesFor("/api/v1/workspaces/{$this->workspace->id}/documents?per_page=15");
        $thirty = $this->queriesFor("/api/v1/workspaces/{$this->workspace->id}/documents?per_page=30");

        $this->assertSame($fifteen, $thirty);
    }

    public function test_an_api_listing_costs_less_than_the_page_it_mirrors()
    {
        $api = $this->queriesFor("/api/v1/workspaces/{$this->workspace->id}/documents");

        $this->assertLessThan(14, $api);
    }
}
