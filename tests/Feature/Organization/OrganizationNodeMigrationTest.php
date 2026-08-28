<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Actions\Organization\CreateOrganizationNode;
use App\Actions\Organization\CreateScheme;
use App\Enums\NodeValueStrategy;
use App\Enums\WorkspaceRole;
use App\Jobs\BulkMoveDocuments;
use App\Models\OrganizationNode;
use App\Models\OrganizationScheme;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OrganizationNodeMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_admin_can_queue_a_migration_between_two_nodes()
    {
        Queue::fake([BulkMoveDocuments::class]);

        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $scheme = $this->createScheme($workspace);
        $source = $this->createNode($scheme, '001');
        $target = $this->createNode($scheme, '002');

        $response = $this->actingAs($admin->user)->post(route('organization.nodes.migrate', $source), [
            'target_node_id' => $target->id,
        ]);

        $response->assertRedirect();
        Queue::assertPushed(BulkMoveDocuments::class, fn (BulkMoveDocuments $job) => $job->source->id === $source->id && $job->target->id === $target->id);
    }

    public function test_non_admin_member_cannot_queue_a_migration()
    {
        Queue::fake([BulkMoveDocuments::class]);

        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $scheme = $this->createScheme($workspace);
        $source = $this->createNode($scheme, '001');
        $target = $this->createNode($scheme, '002');

        $response = $this->actingAs($member->user)->post(route('organization.nodes.migrate', $source), [
            'target_node_id' => $target->id,
        ]);

        $response->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_target_node_must_differ_from_the_source_node()
    {
        Queue::fake([BulkMoveDocuments::class]);

        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $scheme = $this->createScheme($workspace);
        $source = $this->createNode($scheme, '001');

        $response = $this->actingAs($admin->user)->post(route('organization.nodes.migrate', $source), [
            'target_node_id' => $source->id,
        ]);

        $response->assertSessionHasErrors('target_node_id');
        Queue::assertNothingPushed();
    }

    public function test_target_node_must_belong_to_the_same_workspace()
    {
        Queue::fake([BulkMoveDocuments::class]);

        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $scheme = $this->createScheme($workspace);
        $source = $this->createNode($scheme, '001');

        $foreignScheme = $this->createScheme(Workspace::factory()->create());
        $foreignTarget = $this->createNode($foreignScheme, '001');

        $response = $this->actingAs($admin->user)->post(route('organization.nodes.migrate', $source), [
            'target_node_id' => $foreignTarget->id,
        ]);

        $response->assertNotFound();
        Queue::assertNothingPushed();
    }

    private function createScheme(Workspace $workspace): OrganizationScheme
    {
        return app(CreateScheme::class)->handle($workspace, 'Traditional Archive', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);
    }

    private function createNode(OrganizationScheme $scheme, string $value): OrganizationNode
    {
        return app(CreateOrganizationNode::class)->handle($scheme->levels->first(), null, $value);
    }
}
