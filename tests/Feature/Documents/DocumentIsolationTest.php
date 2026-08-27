<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\CreateDocument;
use App\Actions\Organization\CreateOrganizationNode;
use App\Actions\Organization\CreateScheme;
use App\Enums\NodeValueStrategy;
use App\Enums\WorkspaceRole;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Tag;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_outside_the_workspace_cannot_create_update_delete_or_move_a_document()
    {
        $outsider = WorkspaceUser::factory()->create(['role' => WorkspaceRole::Admin]);
        $workspace = Workspace::factory()->create();
        $creator = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $creator->user, $type, 'Original', null, null);

        $scheme = app(CreateScheme::class)->handle($workspace, 'Scheme', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);
        $node = app(CreateOrganizationNode::class)->handle($scheme->levels->first(), null, '001');

        $this->actingAs($outsider->user)
            ->post(route('documents.store', $workspace), ['document_type_id' => $type->id, 'title' => 'Hijacked'])
            ->assertForbidden();

        $this->actingAs($outsider->user)
            ->patch(route('documents.update', $document), ['document_type_id' => $type->id, 'title' => 'Hijacked'])
            ->assertForbidden();

        $this->actingAs($outsider->user)
            ->delete(route('documents.destroy', $document))
            ->assertForbidden();

        $this->actingAs($outsider->user)
            ->post(route('documents.move', $document), ['node_id' => $node->id])
            ->assertForbidden();
    }

    public function test_a_tag_from_a_different_workspace_is_silently_dropped_not_attached()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        $foreignTag = Tag::factory()->create();

        $response = $this->actingAs($member->user)->post(route('documents.store', $workspace), [
            'document_type_id' => $type->id,
            'title' => 'Untagged from outside',
            'tag_ids' => [$foreignTag->id],
        ]);

        $response->assertRedirect();

        $document = Document::query()->where('title', 'Untagged from outside')->firstOrFail();

        $this->assertTrue($document->tags->isEmpty());
    }
}
