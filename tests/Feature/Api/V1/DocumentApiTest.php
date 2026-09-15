<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Enums\WorkspaceRole;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Tag;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Support\PageSize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentApiTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $user;

    private DocumentType $type;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($this->workspace)->create(['role' => WorkspaceRole::Admin]);
        $this->user = $member->user;
        $this->type = DocumentType::factory()->for($this->workspace)->create();
        $this->token = $this->user->createToken('CLI')->plainTextToken;
    }

    public function test_a_document_can_be_registered_and_read_back()
    {
        $tag = Tag::factory()->for($this->workspace)->create();

        $created = $this->withToken($this->token)->postJson(
            "/api/v1/workspaces/{$this->workspace->id}/documents",
            [
                'document_type_id' => $this->type->id,
                'title' => 'Rental agreement',
                'document_date' => '2026-03-01',
                'metadata' => ['landlord' => 'Silva'],
                'tag_ids' => [$tag->id],
            ],
        );

        $created->assertCreated()
            ->assertJsonPath('data.title', 'Rental agreement')
            ->assertJsonPath('data.document_date', '2026-03-01')
            ->assertJsonPath('data.metadata.landlord', 'Silva')
            ->assertJsonPath('data.document_type.id', $this->type->id)
            ->assertJsonPath('data.tags.0.id', $tag->id)
            ->assertJsonPath('data.creator.id', $this->user->id);

        $id = $created->json('data.id');

        $this->withToken($this->token)->getJson("/api/v1/documents/{$id}")
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.workspace_id', $this->workspace->id);
    }

    public function test_the_listing_pages_and_reports_the_page_size_it_used()
    {
        Document::factory()->count(3)->for($this->workspace)->for($this->type)->create();

        $response = $this->withToken($this->token)
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/documents?per_page=2");

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3);
    }

    public function test_a_page_size_beyond_the_ceiling_is_clamped()
    {
        $response = $this->withToken($this->token)
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/documents?per_page=9999");

        $response->assertOk()->assertJsonPath('meta.per_page', PageSize::MAX);
    }

    public function test_the_listing_is_also_the_search()
    {
        Document::factory()->for($this->workspace)->for($this->type)->create(['title' => 'Rental agreement']);
        Document::factory()->for($this->workspace)->for($this->type)->create(['title' => 'Electricity bill']);

        $response = $this->withToken($this->token)
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/documents?q=rental");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Rental agreement');
    }

    public function test_a_document_can_be_updated()
    {
        $document = Document::factory()->for($this->workspace)->for($this->type)->create(['title' => 'Draft']);

        $response = $this->withToken($this->token)->patchJson("/api/v1/documents/{$document->id}", [
            'document_type_id' => $this->type->id,
            'title' => 'Signed',
        ]);

        $response->assertOk()->assertJsonPath('data.title', 'Signed');
        $this->assertSame('Signed', $document->refresh()->title);
    }

    public function test_deleting_a_document_trashes_it_rather_than_destroying_it()
    {
        $document = Document::factory()->for($this->workspace)->for($this->type)->create();

        $this->withToken($this->token)
            ->deleteJson("/api/v1/documents/{$document->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('documents', ['id' => $document->id]);
    }

    /**
     * The rules behind these ids are `exists:` checks, which say the row is
     * there and nothing about whose it is. What stops a document being filed
     * under another workspace's type is the scoped resolve, not validation.
     */
    public function test_another_workspace_s_document_type_is_not_found()
    {
        $otherType = DocumentType::factory()->for(Workspace::factory()->create())->create();

        $response = $this->withToken($this->token)->postJson(
            "/api/v1/workspaces/{$this->workspace->id}/documents",
            ['document_type_id' => $otherType->id, 'title' => 'Smuggled'],
        );

        $response->assertNotFound();
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_another_workspace_s_tags_are_dropped_rather_than_applied()
    {
        $otherTag = Tag::factory()->for(Workspace::factory()->create())->create();

        $response = $this->withToken($this->token)->postJson(
            "/api/v1/workspaces/{$this->workspace->id}/documents",
            [
                'document_type_id' => $this->type->id,
                'title' => 'Rental agreement',
                'tag_ids' => [$otherTag->id],
            ],
        );

        $response->assertCreated()->assertJsonCount(0, 'data.tags');
    }

    public function test_a_token_cannot_reach_a_workspace_its_user_is_not_in()
    {
        $stranger = Workspace::factory()->create();

        $this->withToken($this->token)
            ->getJson("/api/v1/workspaces/{$stranger->id}/documents")
            ->assertForbidden();
    }

    public function test_a_token_cannot_read_a_document_from_another_workspace()
    {
        $stranger = Workspace::factory()->create();
        $document = Document::factory()
            ->for($stranger)
            ->for(DocumentType::factory()->for($stranger))
            ->create();

        $this->withToken($this->token)
            ->getJson("/api/v1/documents/{$document->id}")
            ->assertForbidden();
    }

    public function test_a_document_that_is_in_the_trash_is_not_reachable()
    {
        $document = Document::factory()->for($this->workspace)->for($this->type)->create();
        $document->delete();

        $this->withToken($this->token)
            ->getJson("/api/v1/documents/{$document->id}")
            ->assertNotFound();
    }

    public function test_a_missing_title_is_reported_as_a_validation_error()
    {
        $response = $this->withToken($this->token)->postJson(
            "/api/v1/workspaces/{$this->workspace->id}/documents",
            ['document_type_id' => $this->type->id],
        );

        $response->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['title']]);
    }
}
