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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VocabularyApiTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($this->workspace)->create(['role' => WorkspaceRole::Admin]);
        $this->user = $member->user;
        $this->token = $this->user->createToken('CLI')->plainTextToken;
    }

    public function test_document_types_can_be_listed_created_renamed_and_deleted()
    {
        $created = $this->withToken($this->token)->postJson(
            "/api/v1/workspaces/{$this->workspace->id}/document-types",
            ['name' => 'Invoice', 'key' => 'invoice'],
        );

        $created->assertCreated()->assertJsonPath('data.key', 'invoice');
        $id = $created->json('data.id');

        $this->withToken($this->token)
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/document-types")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.documents_count', 0);

        $this->withToken($this->token)
            ->patchJson("/api/v1/document-types/{$id}", ['name' => 'Bill', 'key' => 'bill'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Bill');

        $this->withToken($this->token)
            ->deleteJson("/api/v1/document-types/{$id}")
            ->assertNoContent();

        $this->assertDatabaseCount('document_types', 0);
    }

    public function test_a_document_type_still_in_use_cannot_be_deleted()
    {
        $type = DocumentType::factory()->for($this->workspace)->create();
        Document::factory()->for($this->workspace)->for($type)->create();

        $response = $this->withToken($this->token)->deleteJson("/api/v1/document-types/{$type->id}");

        $response->assertStatus(422)->assertJsonStructure(['message', 'errors' => ['document_type']]);
        $this->assertDatabaseCount('document_types', 1);
    }

    /**
     * The uniqueness rules live on the Form Requests shared with the web
     * routes, and they scope themselves by reading the workspace off the
     * route. That only holds while the API route parameter is named the same.
     */
    public function test_a_duplicate_key_within_the_workspace_is_rejected()
    {
        DocumentType::factory()->for($this->workspace)->create(['key' => 'invoice', 'name' => 'Invoice']);

        $response = $this->withToken($this->token)->postJson(
            "/api/v1/workspaces/{$this->workspace->id}/document-types",
            ['name' => 'Second', 'key' => 'invoice'],
        );

        $response->assertStatus(422)->assertJsonStructure(['errors' => ['key']]);
    }

    public function test_the_same_key_is_free_in_another_workspace()
    {
        $other = Workspace::factory()->create();
        DocumentType::factory()->for($other)->create(['key' => 'invoice', 'name' => 'Invoice']);

        $this->withToken($this->token)->postJson(
            "/api/v1/workspaces/{$this->workspace->id}/document-types",
            ['name' => 'Invoice', 'key' => 'invoice'],
        )->assertCreated();
    }

    public function test_tags_can_be_listed_created_renamed_and_deleted()
    {
        $created = $this->withToken($this->token)->postJson(
            "/api/v1/workspaces/{$this->workspace->id}/tags",
            ['name' => 'Urgent'],
        );

        $created->assertCreated()->assertJsonPath('data.name', 'Urgent');
        $id = $created->json('data.id');

        $this->withToken($this->token)
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/tags")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.documents_count', 0);

        $this->withToken($this->token)
            ->patchJson("/api/v1/tags/{$id}", ['name' => 'Later'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Later');

        $this->withToken($this->token)->deleteJson("/api/v1/tags/{$id}")->assertNoContent();
        $this->assertDatabaseCount('tags', 0);
    }

    /**
     * Unlike a document type, a tag in use is not protected: losing a label is
     * not losing a filing.
     */
    public function test_a_tag_in_use_is_deleted_and_detached()
    {
        $type = DocumentType::factory()->for($this->workspace)->create();
        $tag = Tag::factory()->for($this->workspace)->create();
        $document = Document::factory()->for($this->workspace)->for($type)->create();
        $document->tags()->attach($tag->id);

        $this->withToken($this->token)->deleteJson("/api/v1/tags/{$tag->id}")->assertNoContent();

        $this->assertDatabaseCount('tags', 0);
        $this->assertCount(0, $document->refresh()->tags);
    }

    public function test_another_workspace_s_vocabulary_is_out_of_reach()
    {
        $stranger = Workspace::factory()->create();
        $type = DocumentType::factory()->for($stranger)->create();
        $tag = Tag::factory()->for($stranger)->create();

        $this->withToken($this->token)
            ->getJson("/api/v1/workspaces/{$stranger->id}/tags")
            ->assertForbidden();

        $this->withToken($this->token)
            ->deleteJson("/api/v1/document-types/{$type->id}")
            ->assertForbidden();

        $this->withToken($this->token)
            ->patchJson("/api/v1/tags/{$tag->id}", ['name' => 'Mine now'])
            ->assertForbidden();
    }
}
