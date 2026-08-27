<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Actions\Organization\CreateScheme;
use App\Enums\NodeValueStrategy;
use App\Enums\WorkspaceRole;
use App\Jobs\BulkMoveDocuments;
use App\Models\OrganizationScheme;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OrganizationSchemeMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_admin_can_queue_a_migration_between_two_schemes()
    {
        Queue::fake([BulkMoveDocuments::class]);

        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $source = $this->createScheme($workspace, 'Source');
        $target = $this->createScheme($workspace, 'Target');

        $response = $this->actingAs($admin->user)->post(route('organization.schemes.migrate', $source), [
            'target_scheme_id' => $target->id,
        ]);

        $response->assertRedirect();
        Queue::assertPushed(BulkMoveDocuments::class, fn (BulkMoveDocuments $job) => $job->source->id === $source->id && $job->target->id === $target->id);
    }

    public function test_non_admin_member_cannot_queue_a_migration()
    {
        Queue::fake([BulkMoveDocuments::class]);

        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $source = $this->createScheme($workspace, 'Source');
        $target = $this->createScheme($workspace, 'Target');

        $response = $this->actingAs($member->user)->post(route('organization.schemes.migrate', $source), [
            'target_scheme_id' => $target->id,
        ]);

        $response->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_target_scheme_must_differ_from_the_source_scheme()
    {
        Queue::fake([BulkMoveDocuments::class]);

        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $source = $this->createScheme($workspace, 'Source');

        $response = $this->actingAs($admin->user)->post(route('organization.schemes.migrate', $source), [
            'target_scheme_id' => $source->id,
        ]);

        $response->assertSessionHasErrors('target_scheme_id');
        Queue::assertNothingPushed();
    }

    public function test_target_scheme_must_belong_to_the_same_workspace()
    {
        Queue::fake([BulkMoveDocuments::class]);

        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $source = $this->createScheme($workspace, 'Source');
        $foreignTarget = $this->createScheme(Workspace::factory()->create(), 'Foreign Target');

        $response = $this->actingAs($admin->user)->post(route('organization.schemes.migrate', $source), [
            'target_scheme_id' => $foreignTarget->id,
        ]);

        $response->assertNotFound();
        Queue::assertNothingPushed();
    }

    private function createScheme(Workspace $workspace, string $name): OrganizationScheme
    {
        return app(CreateScheme::class)->handle($workspace, $name, [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);
    }
}
