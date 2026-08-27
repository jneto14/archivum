<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Actions\Documents\CreateDocument;
use App\Enums\WorkspaceRole;
use App\Models\DocumentType;
use App\Models\Tag;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TagTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_list_tags()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        $tag = Tag::factory()->for($workspace)->create(['name' => 'Urgent']);
        $document = app(CreateDocument::class)->handle($workspace, $member->user, $type, 'Doc', null, null, [$tag->id]);

        $this->actingAs($member->user)
            ->get(route('tags.index', $workspace))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('tags/index')
                ->has('tags', 1)
                ->where('tags.0.name', 'Urgent')
                ->where('tags.0.documents_count', 1),
            );

        $this->assertNotNull($document);
    }

    public function test_non_member_cannot_list_tags()
    {
        $workspace = Workspace::factory()->create();
        $outsider = WorkspaceUser::factory()->create(['role' => WorkspaceRole::Admin]);

        $this->actingAs($outsider->user)
            ->get(route('tags.index', $workspace))
            ->assertForbidden();
    }

    public function test_member_can_rename_a_tag()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $tag = Tag::factory()->for($workspace)->create(['name' => 'Old']);

        $response = $this->actingAs($member->user)->patch(route('tags.update', $tag), [
            'name' => 'New',
        ]);

        $response->assertRedirect();
        $this->assertSame('New', $tag->fresh()->name);
    }

    public function test_renaming_to_a_name_already_used_in_the_workspace_is_rejected()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        Tag::factory()->for($workspace)->create(['name' => 'Urgent']);
        $tag = Tag::factory()->for($workspace)->create(['name' => 'Later']);

        $response = $this->actingAs($member->user)->patch(route('tags.update', $tag), [
            'name' => 'Urgent',
        ]);

        $response->assertSessionHasErrors('name');
        $this->assertSame('Later', $tag->fresh()->name);
    }

    public function test_member_can_delete_a_tag_which_detaches_it_from_documents()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        $tag = Tag::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $member->user, $type, 'Doc', null, null, [$tag->id]);

        $response = $this->actingAs($member->user)->delete(route('tags.destroy', $tag));

        $response->assertRedirect();
        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
        $this->assertDatabaseHas('documents', ['id' => $document->id]);
    }

    public function test_outsider_cannot_manage_a_tag()
    {
        $workspace = Workspace::factory()->create();
        $outsider = WorkspaceUser::factory()->create(['role' => WorkspaceRole::Admin]);
        $tag = Tag::factory()->for($workspace)->create();

        $this->actingAs($outsider->user)
            ->patch(route('tags.update', $tag), ['name' => 'New'])
            ->assertForbidden();

        $this->actingAs($outsider->user)
            ->delete(route('tags.destroy', $tag))
            ->assertForbidden();
    }
}
