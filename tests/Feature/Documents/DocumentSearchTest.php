<?php

namespace Tests\Feature\Documents;

use App\Enums\WorkspaceRole;
use App\Models\Document;
use App\Models\DocumentType;
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

    public function test_non_member_cannot_search()
    {
        $workspace = Workspace::factory()->create();
        $outsider = WorkspaceUser::factory()->create(['role' => WorkspaceRole::Admin]);

        $response = $this->actingAs($outsider->user)
            ->getJson(route('documents.search', ['workspace' => $workspace, 'q' => 'anything']));

        $response->assertForbidden();
    }
}
