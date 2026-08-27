<?php

declare(strict_types=1);

namespace Tests\Feature\Workspaces;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class WorkspaceIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_cannot_update_a_workspace_they_do_not_belong_to()
    {
        $outsider = WorkspaceUser::factory()->create(['role' => WorkspaceRole::Admin]);
        $otherWorkspace = Workspace::factory()->create();

        $response = $this->actingAs($outsider->user)->patch(route('workspaces.update', $otherWorkspace), [
            'name' => 'Hijacked',
        ]);

        $response->assertForbidden();
        $this->assertNotSame('Hijacked', $otherWorkspace->fresh()->name);
    }

    public function test_user_cannot_manage_members_of_a_workspace_they_do_not_belong_to()
    {
        $outsider = WorkspaceUser::factory()->create(['role' => WorkspaceRole::Admin]);
        $otherWorkspace = Workspace::factory()->create();
        $otherMember = WorkspaceUser::factory()->for($otherWorkspace)->create(['role' => WorkspaceRole::User]);
        $intruderTarget = User::factory()->create();

        $this->actingAs($outsider->user)
            ->post(route('workspaces.users.store', $otherWorkspace), [
                'email' => $intruderTarget->email,
                'role' => WorkspaceRole::User->value,
            ])
            ->assertForbidden();

        $this->actingAs($outsider->user)
            ->patch(route('workspaces.users.update', [$otherWorkspace, $otherMember->user]), [
                'role' => WorkspaceRole::Admin->value,
            ])
            ->assertForbidden();

        $this->actingAs($outsider->user)
            ->delete(route('workspaces.users.destroy', [$otherWorkspace, $otherMember->user]))
            ->assertForbidden();

        $this->assertFalse($otherWorkspace->isMember($intruderTarget));
        $this->assertFalse($otherWorkspace->isAdmin($otherMember->user));
        $this->assertTrue($otherWorkspace->isMember($otherMember->user));
    }

    public function test_user_cannot_switch_into_a_workspace_they_do_not_belong_to()
    {
        $member = WorkspaceUser::factory()->create(['role' => WorkspaceRole::User]);
        $otherWorkspace = Workspace::factory()->create();

        $response = $this->actingAs($member->user)->post(route('workspaces.switch', $otherWorkspace));

        $response->assertForbidden();
        $this->assertNotSame($otherWorkspace->id, session('current_workspace_id'));
    }

    public function test_nonexistent_workspace_or_user_returns_not_found_rather_than_forbidden()
    {
        $admin = WorkspaceUser::factory()->create(['role' => WorkspaceRole::Admin]);

        $this->actingAs($admin->user)
            ->patch(route('workspaces.update', '00000000-0000-7000-8000-000000000000'), ['name' => 'X'])
            ->assertNotFound();

        $this->actingAs($admin->user)
            ->delete(route('workspaces.users.destroy', [$admin->workspace, '00000000-0000-7000-8000-000000000000']))
            ->assertNotFound();
    }

    public function test_resolve_workspace_middleware_revalidates_membership_on_every_request()
    {
        Route::middleware(['web', 'auth'])->get('/__test/current-workspace', function (Request $request) {
            return response()->json(['workspace_id' => $request->attributes->get('workspace')->id]);
        });

        $workspaceOne = Workspace::factory()->create();
        $workspaceTwo = Workspace::factory()->create();
        $user = User::factory()->create();
        $membershipOne = WorkspaceUser::factory()->for($workspaceOne)->for($user, 'user')->create(['role' => WorkspaceRole::Admin]);
        WorkspaceUser::factory()->for($workspaceTwo)->for($user, 'user')->create(['role' => WorkspaceRole::User]);

        $this->actingAs($user)->post(route('workspaces.switch', $workspaceOne))->assertRedirect();

        $this->actingAs($user)
            ->getJson('/__test/current-workspace')
            ->assertOk()
            ->assertJson(['workspace_id' => $workspaceOne->id]);

        $membershipOne->delete();

        $response = $this->actingAs($user)->getJson('/__test/current-workspace');

        $response->assertOk();
        $this->assertNotSame($workspaceOne->id, $response->json('workspace_id'));
        $this->assertSame($workspaceTwo->id, $response->json('workspace_id'));
    }

    public function test_resolve_workspace_middleware_resolves_to_null_for_a_user_with_no_memberships()
    {
        Route::middleware(['web', 'auth'])->get('/__test/current-workspace', function (Request $request) {
            return response()->json(['workspace_id' => $request->attributes->get('workspace')?->id]);
        });

        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/__test/current-workspace')
            ->assertOk()
            ->assertJson(['workspace_id' => null]);
    }

    public function test_resolve_workspace_middleware_does_not_error_for_guests()
    {
        Route::middleware(['web'])->get('/__test/current-workspace', function (Request $request) {
            return response()->json(['workspace_id' => $request->attributes->get('workspace')?->id]);
        });

        $this->getJson('/__test/current-workspace')
            ->assertOk()
            ->assertJson(['workspace_id' => null]);
    }

    public function test_resolve_workspace_middleware_in_single_workspace_mode_resolves_for_the_sole_members()
    {
        Route::middleware(['web', 'auth'])->get('/__test/current-workspace', function (Request $request) {
            return response()->json(['workspace_id' => $request->attributes->get('workspace')?->id]);
        });

        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);

        config(['archivum.multi_workspace_enabled' => false]);

        $this->actingAs($member->user)
            ->getJson('/__test/current-workspace')
            ->assertOk()
            ->assertJson(['workspace_id' => $workspace->id]);
    }

    public function test_resolve_workspace_middleware_in_single_workspace_mode_resolves_to_null_for_a_non_member()
    {
        Route::middleware(['web', 'auth'])->get('/__test/current-workspace', function (Request $request) {
            return response()->json(['workspace_id' => $request->attributes->get('workspace')?->id]);
        });

        Workspace::factory()->create();
        $outsider = User::factory()->create();

        config(['archivum.multi_workspace_enabled' => false]);

        $this->actingAs($outsider)
            ->getJson('/__test/current-workspace')
            ->assertOk()
            ->assertJson(['workspace_id' => null]);
    }
}
