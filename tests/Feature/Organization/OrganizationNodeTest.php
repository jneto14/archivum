<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Actions\Documents\CreateDocument;
use App\Actions\Documents\MoveDocument;
use App\Actions\Organization\CreateOrganizationNode;
use App\Actions\Organization\CreateScheme;
use App\Enums\NodeValueStrategy;
use App\Enums\WorkspaceRole;
use App\Models\DocumentType;
use App\Models\OrganizationLevel;
use App\Models\OrganizationScheme;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OrganizationNodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_admin_can_create_a_root_node()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $scheme = $this->createScheme($workspace);
        $level = $scheme->levels->first();

        $response = $this->actingAs($admin->user)->post(route('organization.schemes.nodes.store', $scheme), [
            'level_id' => $level->id,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('organization_nodes', ['level_id' => $level->id, 'value' => '001']);
    }

    public function test_nodes_can_be_created_in_a_scheme_whose_levels_do_not_start_at_position_one()
    {
        $workspace = Workspace::factory()->create();
        $scheme = OrganizationScheme::factory()->for($workspace)->create();

        // The demo seeder numbered its levels from zero, which left the top two
        // levels of that archive unable to take nodes at all: the root was not
        // recognised as the root, and the level below it was.
        $room = OrganizationLevel::factory()->for($scheme, 'scheme')->create([
            'name' => 'Room', 'key' => 'room', 'position' => 0, 'value_strategy' => NodeValueStrategy::Manual,
        ]);
        $cabinet = OrganizationLevel::factory()->for($scheme, 'scheme')->create([
            'name' => 'Cabinet', 'key' => 'cabinet', 'position' => 1, 'value_strategy' => NodeValueStrategy::Alphabetical,
        ]);

        $action = app(CreateOrganizationNode::class);
        $roomNode = $action->handle($room, null, 'Floor 1');
        $cabinetNode = $action->handle($cabinet, $roomNode);

        $this->assertSame('Floor 1-A', $cabinetNode->path());
    }

    public function test_path_accessor_joins_values_from_root_to_leaf()
    {
        $workspace = Workspace::factory()->create();
        $scheme = $this->createScheme($workspace);
        $levels = $scheme->levels()->orderBy('position')->get();
        $cover = $levels[0];
        $letter = $levels[1];
        $position = $levels[2];

        $action = app(CreateOrganizationNode::class);
        $coverNode = $action->handle($cover, null, '001');
        $letterNode = $action->handle($letter, $coverNode, 'A');
        $positionNode = $action->handle($position, $letterNode);

        $this->assertSame('001-A-001', $positionNode->path());
    }

    public function test_a_node_cannot_be_attached_to_a_parent_from_the_wrong_level()
    {
        $workspace = Workspace::factory()->create();
        $scheme = $this->createScheme($workspace);
        $levels = $scheme->levels()->orderBy('position')->get();
        $cover = $levels[0];
        $position = $levels[2];

        $action = app(CreateOrganizationNode::class);
        $coverNode = $action->handle($cover, null, '001');

        $this->expectException(ValidationException::class);

        $action->handle($position, $coverNode);
    }

    public function test_non_admin_member_cannot_create_a_node()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $scheme = $this->createScheme($workspace);
        $level = $scheme->levels->first();

        $response = $this->actingAs($member->user)->post(route('organization.schemes.nodes.store', $scheme), [
            'level_id' => $level->id,
        ]);

        $response->assertForbidden();
    }

    public function test_capacity_exceeded_returns_a_validation_error()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $scheme = $this->createScheme($workspace, coverCapacity: 1);
        $level = $scheme->levels->first();

        $this->actingAs($admin->user)->post(route('organization.schemes.nodes.store', $scheme), [
            'level_id' => $level->id,
        ])->assertRedirect();

        $response = $this->actingAs($admin->user)->post(route('organization.schemes.nodes.store', $scheme), [
            'level_id' => $level->id,
        ]);

        $response->assertSessionHasErrors('capacity');
    }

    public function test_workspace_admin_can_delete_a_leaf_node()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $scheme = $this->createScheme($workspace);
        $node = app(CreateOrganizationNode::class)->handle($scheme->levels->first(), null, '001');

        $response = $this->actingAs($admin->user)->delete(route('organization.schemes.nodes.destroy', [$scheme, $node]));

        $response->assertRedirect();
        $this->assertDatabaseMissing('organization_nodes', ['id' => $node->id]);
    }

    public function test_non_admin_member_cannot_delete_a_node()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $scheme = $this->createScheme($workspace);
        $node = app(CreateOrganizationNode::class)->handle($scheme->levels->first(), null, '001');

        $response = $this->actingAs($member->user)->delete(route('organization.schemes.nodes.destroy', [$scheme, $node]));

        $response->assertForbidden();
        $this->assertDatabaseHas('organization_nodes', ['id' => $node->id]);
    }

    public function test_a_node_with_children_cannot_be_deleted()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $scheme = $this->createScheme($workspace);
        $levels = $scheme->levels()->orderBy('position')->get();
        $action = app(CreateOrganizationNode::class);
        $cover = $action->handle($levels[0], null, '001');
        $action->handle($levels[1], $cover, 'A');

        $response = $this->actingAs($admin->user)->delete(route('organization.schemes.nodes.destroy', [$scheme, $cover]));

        $response->assertSessionHasErrors('node');
        $this->assertDatabaseHas('organization_nodes', ['id' => $cover->id]);
    }

    public function test_a_node_with_documents_currently_located_at_it_cannot_be_deleted()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $scheme = $this->createScheme($workspace);
        $node = app(CreateOrganizationNode::class)->handle($scheme->levels->first(), null, '001');
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $admin->user, $type, 'Filed', null, null);
        app(MoveDocument::class)->handle($document, $node);

        $response = $this->actingAs($admin->user)->delete(route('organization.schemes.nodes.destroy', [$scheme, $node]));

        $response->assertSessionHasErrors('node');
        $this->assertDatabaseHas('organization_nodes', ['id' => $node->id]);
    }

    public function test_workspace_admin_can_create_a_child_node_under_a_parent()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $scheme = $this->createScheme($workspace);
        $levels = $scheme->levels()->orderBy('position')->get();
        $cover = app(CreateOrganizationNode::class)->handle($levels[0], null, '001');

        $response = $this->actingAs($admin->user)->post(route('organization.schemes.nodes.store', $scheme), [
            'level_id' => $levels[1]->id,
            'parent_id' => $cover->id,
            'value' => 'A',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('organization_nodes', [
            'level_id' => $levels[1]->id,
            'parent_id' => $cover->id,
            'value' => 'A',
        ]);
    }

    public function test_a_node_cannot_be_created_under_a_parent_from_another_scheme()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $scheme = $this->createScheme($workspace);

        // A workspace holds one scheme, so the foreign parent has to come from
        // a second workspace this admin also belongs to.
        $otherWorkspace = Workspace::factory()->create();
        WorkspaceUser::factory()->for($otherWorkspace)->create([
            'user_id' => $admin->user_id,
            'role' => WorkspaceRole::Admin,
        ]);
        $otherScheme = $this->createScheme($otherWorkspace);
        $foreignParent = app(CreateOrganizationNode::class)
            ->handle($otherScheme->levels()->orderBy('position')->first(), null, '001');

        $response = $this->actingAs($admin->user)->post(route('organization.schemes.nodes.store', $scheme), [
            'level_id' => $scheme->levels()->orderBy('position')->skip(1)->first()->id,
            'parent_id' => $foreignParent->id,
            'value' => 'A',
        ]);

        $response->assertNotFound();
        $this->assertDatabaseMissing('organization_nodes', ['parent_id' => $foreignParent->id]);
    }

    private function createScheme(Workspace $workspace, ?int $coverCapacity = null): OrganizationScheme
    {
        return app(CreateScheme::class)->handle($workspace, 'Traditional Archive', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential, 'capacity' => $coverCapacity],
            ['name' => 'Letter', 'key' => 'letter', 'value_strategy' => NodeValueStrategy::Manual],
            ['name' => 'Position', 'key' => 'position', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);
    }
}
