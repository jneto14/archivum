<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Actions\Documents\CreateDocument;
use App\Actions\Documents\MoveDocument;
use App\Actions\Organization\CreateOrganizationNode;
use App\Actions\Organization\CreateScheme;
use App\Enums\NodeValueStrategy;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Enums\WorkspaceRole;
use App\Jobs\BulkMoveDocuments;
use App\Models\DocumentType;
use App\Models\OrganizationNode;
use App\Models\OrganizationScheme;
use App\Models\Task;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

        $task = Task::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame(TaskType::BulkDocumentMove, $task->type);
        $this->assertSame(TaskStatus::Queued, $task->status);
        $this->assertSame($admin->user->id, $task->user_id);

        Queue::assertPushed(BulkMoveDocuments::class, fn (BulkMoveDocuments $job) => $job->task->id === $task->id && $job->source->id === $source->id && $job->target->id === $target->id);
    }

    public function test_starting_a_migration_while_one_is_already_running_is_rejected()
    {
        Queue::fake([BulkMoveDocuments::class]);

        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $scheme = $this->createScheme($workspace);
        $source = $this->createNode($scheme, '001');
        $target = $this->createNode($scheme, '002');

        $lock = Cache::lock(TaskType::BulkDocumentMove->lockKey($workspace->id), 600);
        $lock->get();

        $response = $this->actingAs($admin->user)->post(route('organization.nodes.migrate', $source), [
            'target_node_id' => $target->id,
        ]);

        $response->assertSessionHasErrors('task');
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('tasks', 0);
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
        $this->assertDatabaseCount('tasks', 0);
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
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_a_migration_the_target_cannot_hold_is_refused_before_it_is_queued()
    {
        Queue::fake([BulkMoveDocuments::class]);

        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $type = DocumentType::factory()->for($workspace)->create();
        $scheme = $this->createScheme($workspace, capacity: 2);
        $source = $this->createNode($scheme, '001');
        $target = $this->createNode($scheme, '002');

        // Two documents on the move, one already at the target, room for two.
        foreach ([$source, $source, $target] as $node) {
            $document = app(CreateDocument::class)->handle($workspace, $admin->user, $type, 'Filed', null, null);
            app(MoveDocument::class)->handle($document, $node);
        }

        $response = $this->actingAs($admin->user)->post(route('organization.nodes.migrate', $source), [
            'target_node_id' => $target->id,
        ]);

        // Told at the dialog rather than by a task that fails minutes later.
        $response->assertSessionHasErrors('target_node_id');
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('tasks', 0);
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
        $this->assertDatabaseCount('tasks', 0);
    }

    private function createScheme(Workspace $workspace, ?int $capacity = null): OrganizationScheme
    {
        return app(CreateScheme::class)->handle($workspace, 'Traditional Archive', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential, 'capacity' => $capacity],
        ]);
    }

    private function createNode(OrganizationScheme $scheme, string $value): OrganizationNode
    {
        return app(CreateOrganizationNode::class)->handle($scheme->levels->first(), null, $value);
    }
}
