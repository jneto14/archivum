<?php

namespace Tests\Feature\Workspaces;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_a_workspace()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('workspaces.store'), [
            'name' => 'Company A',
        ]);

        $response->assertRedirect();

        $workspace = Workspace::query()->where('name', 'Company A')->firstOrFail();

        $this->assertTrue($workspace->isAdmin($user));
        $this->assertSame($workspace->id, session('current_workspace_id'));
    }

    public function test_workspace_creation_is_blocked_when_multi_workspace_is_disabled()
    {
        config(['archivum.multi_workspace_enabled' => false]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('workspaces.store'), [
            'name' => 'Company A',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('workspaces', ['name' => 'Company A']);
    }

    public function test_workspace_admin_can_update_it()
    {
        $workspace = Workspace::factory()->create();
        $membership = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);

        $response = $this->actingAs($membership->user)->patch(route('workspaces.update', $workspace), [
            'name' => 'Renamed',
        ]);

        $response->assertRedirect();
        $this->assertSame('Renamed', $workspace->fresh()->name);
    }

    public function test_non_admin_member_cannot_update_workspace()
    {
        $workspace = Workspace::factory()->create();
        $membership = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);

        $response = $this->actingAs($membership->user)->patch(route('workspaces.update', $workspace), [
            'name' => 'Renamed',
        ]);

        $response->assertForbidden();
    }

    public function test_non_member_cannot_update_workspace()
    {
        $outsider = User::factory()->create();
        $workspace = Workspace::factory()->create();

        $response = $this->actingAs($outsider)->patch(route('workspaces.update', $workspace), [
            'name' => 'Renamed',
        ]);

        $response->assertForbidden();
    }

    public function test_admins_count_and_last_admin_detection()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);

        $this->assertSame(1, $workspace->adminsCount());
        $this->assertTrue($workspace->wouldRemoveLastAdmin($admin->user));
        $this->assertFalse($workspace->wouldRemoveLastAdmin($member->user));
    }
}
