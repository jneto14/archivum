<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\CreateDocument;
use App\Enums\WorkspaceRole;
use App\Models\DocumentType;
use App\Models\Tag;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_creator_can_update_their_own_document_and_tags_are_resynced()
    {
        $workspace = Workspace::factory()->create();
        $creator = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        $tagA = Tag::factory()->for($workspace)->create(['name' => 'A']);
        $tagB = Tag::factory()->for($workspace)->create(['name' => 'B']);

        $document = app(CreateDocument::class)->handle($workspace, $creator->user, $type, 'Original', null, null, [$tagA->id]);

        $response = $this->actingAs($creator->user)->patch(route('documents.update', $document), [
            'document_type_id' => $type->id,
            'title' => 'Renamed',
            'tag_ids' => [$tagB->id],
        ]);

        $response->assertRedirect();

        $document->refresh();

        $this->assertSame('Renamed', $document->title);
        $this->assertSame(['B'], $document->tags->pluck('name')->all());
    }

    public function test_workspace_admin_can_update_any_members_document()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $creator = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();

        $document = app(CreateDocument::class)->handle($workspace, $creator->user, $type, 'Original', null, null);

        $response = $this->actingAs($admin->user)->patch(route('documents.update', $document), [
            'document_type_id' => $type->id,
            'title' => 'Renamed by admin',
        ]);

        $response->assertRedirect();
        $this->assertSame('Renamed by admin', $document->fresh()->title);
    }

    public function test_non_creator_non_admin_member_cannot_update_a_document()
    {
        $workspace = Workspace::factory()->create();
        $creator = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $otherMember = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();

        $document = app(CreateDocument::class)->handle($workspace, $creator->user, $type, 'Original', null, null);

        $response = $this->actingAs($otherMember->user)->patch(route('documents.update', $document), [
            'document_type_id' => $type->id,
            'title' => 'Hijacked',
        ]);

        $response->assertForbidden();
    }
}
