<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Actions\Documents\CreateDocument;
use App\Enums\WorkspaceRole;
use App\Models\Document;
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
                ->where('tags.0.documents_count', 1)
                ->whereNot('tags.0.last_used_at', null),
            );

        $this->assertNotNull($document);
    }

    public function test_member_can_create_a_tag()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);

        $response = $this->actingAs($member->user)
            ->post(route('tags.store', $workspace), ['name' => 'Utilities']);

        $response->assertRedirect();

        $this->assertSame(
            ['Utilities'],
            Tag::query()->where('workspace_id', $workspace->id)->pluck('name')->all(),
        );
    }

    public function test_a_tag_name_must_be_unique_within_the_workspace()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        Tag::factory()->for($workspace)->create(['name' => 'Utilities']);

        $response = $this->actingAs($member->user)
            ->post(route('tags.store', $workspace), ['name' => 'Utilities']);

        $response->assertSessionHasErrors('name');
        $this->assertSame(1, Tag::query()->count());
    }

    public function test_the_same_tag_name_may_exist_in_two_workspaces()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);

        // Uniqueness is scoped to the workspace, so an unscoped unique rule
        // would let one workspace's vocabulary block another's.
        Tag::factory()->create(['name' => 'Utilities']);

        $this->actingAs($member->user)
            ->post(route('tags.store', $workspace), ['name' => 'Utilities'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Tag::query()->where('name', 'Utilities')->count());
    }

    public function test_outsider_cannot_create_a_tag()
    {
        $workspace = Workspace::factory()->create();
        $outsider = WorkspaceUser::factory()->create(['role' => WorkspaceRole::Admin]);

        $this->actingAs($outsider->user)
            ->post(route('tags.store', $workspace), ['name' => 'Utilities'])
            ->assertForbidden();

        $this->assertSame(0, Tag::query()->where('workspace_id', $workspace->id)->count());
    }

    public function test_a_tag_with_no_documents_reports_no_last_used_date()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        Tag::factory()->for($workspace)->create(['name' => 'Unused']);

        $this->actingAs($member->user)
            ->get(route('tags.index', $workspace))
            ->assertInertia(fn (Assert $page) => $page
                ->where('tags.0.documents_count', 0)
                ->where('tags.0.last_used_at', null),
            );
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

    /**
     * By when each was last used, which used to be fetched in a second query
     * and merged in afterwards — a value assembled after the query cannot be
     * ordered by, so it now comes from the query itself.
     */
    public function test_tags_can_be_ordered_by_when_they_were_last_used()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $stale = Tag::factory()->for($workspace)->create(['name' => 'Aardvark']);
        $fresh = Tag::factory()->for($workspace)->create(['name' => 'Zebra']);

        $older = Document::factory()->for($workspace)->create();
        $older->tags()->attach($stale, ['created_at' => now()->subMonth(), 'updated_at' => now()->subMonth()]);

        $newer = Document::factory()->for($workspace)->create();
        $newer->tags()->attach($fresh, ['created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($member->user)
            ->get(route('tags.index', [
                'workspace' => $workspace,
                'sort' => 'last_used_at',
                'direction' => 'desc',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('sort.key', 'last_used_at')
                ->where('tags.0.name', 'Zebra')
                ->where('tags.1.name', 'Aardvark'),
            );
    }

    public function test_a_tag_that_has_never_been_used_still_reports_no_last_use()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        Tag::factory()->for($workspace)->create(['name' => 'Unused']);

        $this->actingAs($member->user)
            ->get(route('tags.index', $workspace))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tags.0.name', 'Unused')
                ->where('tags.0.last_used_at', null),
            );
    }
}
