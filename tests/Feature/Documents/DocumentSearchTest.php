<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Actions\Documents\MoveDocument;
use App\Actions\Organization\CreateOrganizationNode;
use App\Actions\Organization\CreateScheme;
use App\Enums\NodeValueStrategy;
use App\Enums\WorkspaceRole;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\OrganizationScheme;
use App\Models\Tag;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_member_can_search_documents_by_partial_title_match()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        Document::factory()->for($workspace)->create(['title' => 'BMW 320d Service Invoice']);
        Document::factory()->for($workspace)->create(['title' => 'Unrelated Receipt']);

        $response = $this->actingAs($member->user)
            ->getJson(route('documents.search', ['workspace' => $workspace, 'q' => 'BMW']));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.title', 'BMW 320d Service Invoice');
    }

    public function test_document_type_filter_narrows_results()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $invoice = DocumentType::factory()->for($workspace)->create();
        $receipt = DocumentType::factory()->for($workspace)->create();
        Document::factory()->for($workspace)->for($invoice)->create(['title' => 'Matched']);
        Document::factory()->for($workspace)->for($receipt)->create(['title' => 'Matched too']);

        $response = $this->actingAs($member->user)->getJson(
            route('documents.search', ['workspace' => $workspace, 'document_type_id' => $invoice->id])
        );

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    public function test_tag_filter_narrows_results()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $tag = Tag::factory()->for($workspace)->create();
        $tagged = Document::factory()->for($workspace)->create();
        $tagged->tags()->attach($tag);
        Document::factory()->for($workspace)->create();

        $response = $this->actingAs($member->user)->getJson(
            route('documents.search', ['workspace' => $workspace, 'tag_ids' => [$tag->id]])
        );

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $tagged->id);
    }

    public function test_date_range_filter_narrows_results()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        Document::factory()->for($workspace)->create(['document_date' => '2026-01-15']);
        $inRange = Document::factory()->for($workspace)->create(['document_date' => '2026-08-01']);

        $response = $this->actingAs($member->user)->getJson(
            route('documents.search', ['workspace' => $workspace, 'from' => '2026-06-01', 'to' => '2026-12-31'])
        );

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $inRange->id);
    }

    public function test_text_search_and_structured_filters_combine()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        $match = Document::factory()->for($workspace)->for($type)->create(['title' => 'BMW 320d Invoice']);
        Document::factory()->for($workspace)->for($type)->create(['title' => 'BMW 320d Manual']);
        Document::factory()->for($workspace)->create(['title' => 'BMW 320d Invoice']);

        $response = $this->actingAs($member->user)->getJson(route('documents.search', [
            'workspace' => $workspace,
            'q' => 'invoice',
            'document_type_id' => $type->id,
        ]));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $match->id);
    }

    public function test_location_filter_returns_what_is_filed_there_now()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $scheme = $this->createScheme($workspace);
        [$cover, $position] = $scheme->levels()->orderBy('position')->get()->all();

        $createNode = app(CreateOrganizationNode::class);
        $coverNode = $createNode->handle($cover, null, '001');
        $shelf = $createNode->handle($position, $coverNode, '001');
        $otherShelf = $createNode->handle($position, $coverNode, '002');

        $filed = Document::factory()->for($workspace)->create(['title' => 'On the shelf']);
        app(MoveDocument::class)->handle($filed, $shelf);

        $movedOn = Document::factory()->for($workspace)->create(['title' => 'Moved on']);
        app(MoveDocument::class)->handle($movedOn, $shelf);
        app(MoveDocument::class)->handle($movedOn->refresh(), $otherShelf);

        Document::factory()->for($workspace)->create(['title' => 'Never filed']);

        $response = $this->actingAs($member->user)->getJson(
            route('documents.search', ['workspace' => $workspace, 'node_id' => $shelf->id])
        );

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        // Where a document is, not where it has been: the one that moved on
        // still has a location row for this shelf, and must not come back.
        $response->assertJsonPath('data.0.id', $filed->id);
    }

    public function test_filtering_by_a_parent_location_answers_with_everything_below_it()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $scheme = $this->createScheme($workspace);
        [$cover, $position] = $scheme->levels()->orderBy('position')->get()->all();

        $createNode = app(CreateOrganizationNode::class);
        $coverNode = $createNode->handle($cover, null, '001');
        $otherCover = $createNode->handle($cover, null, '002');

        foreach (['001', '002'] as $value) {
            $shelf = $createNode->handle($position, $coverNode, $value);
            $document = Document::factory()->for($workspace)->create(['title' => "Under 001-{$value}"]);
            app(MoveDocument::class)->handle($document, $shelf);
        }

        $elsewhere = $createNode->handle($position, $otherCover, '001');
        app(MoveDocument::class)->handle(Document::factory()->for($workspace)->create(), $elsewhere);

        $response = $this->actingAs($member->user)->getJson(
            route('documents.search', ['workspace' => $workspace, 'node_id' => $coverNode->id])
        );

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    }

    public function test_filtering_by_a_location_from_another_workspace_answers_with_nothing()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        Document::factory()->for($workspace)->create(['title' => 'Ours']);

        $foreignScheme = $this->createScheme(Workspace::factory()->create());
        $foreignNode = app(CreateOrganizationNode::class)->handle($foreignScheme->levels->first(), null, '001');

        $response = $this->actingAs($member->user)->getJson(
            route('documents.search', ['workspace' => $workspace, 'node_id' => $foreignNode->id])
        );

        // Dropping the filter instead would answer "what is in that location"
        // with this workspace's whole archive.
        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    public function test_document_from_another_workspace_never_leaks_into_results()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        Document::factory()->create(['title' => 'BMW 320d Invoice']);

        $response = $this->actingAs($member->user)
            ->getJson(route('documents.search', ['workspace' => $workspace, 'q' => 'BMW']));

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    private function createScheme(Workspace $workspace): OrganizationScheme
    {
        return app(CreateScheme::class)->handle($workspace, 'Traditional Archive', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
            ['name' => 'Position', 'key' => 'position', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);
    }

    public function test_non_member_cannot_search()
    {
        $workspace = Workspace::factory()->create();
        $outsider = WorkspaceUser::factory()->create(['role' => WorkspaceRole::Admin]);

        $response = $this->actingAs($outsider->user)
            ->getJson(route('documents.search', ['workspace' => $workspace, 'q' => 'anything']));

        $response->assertForbidden();
    }
}
