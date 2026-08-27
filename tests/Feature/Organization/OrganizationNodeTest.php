<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Actions\Organization\CreateOrganizationNode;
use App\Actions\Organization\CreateScheme;
use App\Enums\NodeValueStrategy;
use App\Enums\WorkspaceRole;
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

    private function createScheme(Workspace $workspace, ?int $coverCapacity = null): OrganizationScheme
    {
        return app(CreateScheme::class)->handle($workspace, 'Traditional Archive', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential, 'capacity' => $coverCapacity],
            ['name' => 'Letter', 'key' => 'letter', 'value_strategy' => NodeValueStrategy::Manual],
            ['name' => 'Position', 'key' => 'position', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);
    }
}
