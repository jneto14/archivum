<?php

namespace Tests\Feature\Workspaces;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WorkspaceMembershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_add_an_existing_user_by_email()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $newUser = User::factory()->create();

        $response = $this->actingAs($admin->user)->post(route('workspaces.users.store', $workspace), [
            'email' => $newUser->email,
            'role' => WorkspaceRole::User->value,
        ]);

        $response->assertRedirect();
        $this->assertTrue($workspace->isMember($newUser));
        $this->assertFalse($workspace->isAdmin($newUser));
    }

    public function test_admin_can_invite_a_brand_new_user_by_email_and_name()
    {
        Notification::fake();

        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);

        $response = $this->actingAs($admin->user)->post(route('workspaces.users.store', $workspace), [
            'email' => 'new.person@example.com',
            'name' => 'New Person',
            'role' => WorkspaceRole::User->value,
        ]);

        $response->assertRedirect();

        $invited = User::query()->where('email', 'new.person@example.com')->firstOrFail();
        $this->assertSame('New Person', $invited->name);
        $this->assertTrue($workspace->isMember($invited));
        Notification::assertSentTo($invited, ResetPassword::class);
    }

    public function test_inviting_a_new_email_without_a_name_fails_validation()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);

        $response = $this->actingAs($admin->user)->post(route('workspaces.users.store', $workspace), [
            'email' => 'no.name@example.com',
            'role' => WorkspaceRole::User->value,
        ]);

        $response->assertSessionHasErrors('name');
        $this->assertDatabaseMissing('users', ['email' => 'no.name@example.com']);
    }

    public function test_adding_an_already_member_email_fails_validation()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $existingMember = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);

        $response = $this->actingAs($admin->user)->post(route('workspaces.users.store', $workspace), [
            'email' => $existingMember->user->email,
            'role' => WorkspaceRole::User->value,
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertSame(
            2,
            WorkspaceUser::query()->where('workspace_id', $workspace->id)->count(),
        );
    }

    public function test_non_admin_cannot_add_users()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $newUser = User::factory()->create();

        $response = $this->actingAs($member->user)->post(route('workspaces.users.store', $workspace), [
            'email' => $newUser->email,
            'role' => WorkspaceRole::User->value,
        ]);

        $response->assertForbidden();
        $this->assertFalse($workspace->isMember($newUser));
    }

    public function test_admin_can_change_a_members_role()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);

        $response = $this->actingAs($admin->user)->patch(
            route('workspaces.users.update', [$workspace, $member->user]),
            ['role' => WorkspaceRole::Admin->value],
        );

        $response->assertRedirect();
        $this->assertTrue($workspace->isAdmin($member->user));
    }

    public function test_non_admin_cannot_change_roles()
    {
        $workspace = Workspace::factory()->create();
        $memberA = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $memberB = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);

        $response = $this->actingAs($memberA->user)->patch(
            route('workspaces.users.update', [$workspace, $memberB->user]),
            ['role' => WorkspaceRole::Admin->value],
        );

        $response->assertForbidden();
    }

    public function test_admin_can_remove_a_member()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);

        $response = $this->actingAs($admin->user)->delete(
            route('workspaces.users.destroy', [$workspace, $member->user]),
        );

        $response->assertRedirect();
        $this->assertFalse($workspace->isMember($member->user));
    }

    public function test_non_admin_cannot_remove_members()
    {
        $workspace = Workspace::factory()->create();
        $memberA = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $memberB = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);

        $response = $this->actingAs($memberA->user)->delete(
            route('workspaces.users.destroy', [$workspace, $memberB->user]),
        );

        $response->assertForbidden();
        $this->assertTrue($workspace->isMember($memberB->user));
    }

    public function test_last_admin_cannot_be_removed()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);

        $response = $this->actingAs($admin->user)->delete(
            route('workspaces.users.destroy', [$workspace, $admin->user]),
        );

        $response->assertSessionHasErrors('user');
        $this->assertTrue($workspace->fresh()->isMember($admin->user));
    }

    public function test_last_admin_cannot_be_demoted()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);

        $response = $this->actingAs($admin->user)->patch(
            route('workspaces.users.update', [$workspace, $admin->user]),
            ['role' => WorkspaceRole::User->value],
        );

        $response->assertSessionHasErrors('role');
        $this->assertTrue($workspace->isAdmin($admin->user));
    }

    public function test_acting_on_a_non_member_target_user_returns_not_found()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $notAMember = User::factory()->create();

        $response = $this->actingAs($admin->user)->delete(
            route('workspaces.users.destroy', [$workspace, $notAMember]),
        );

        $response->assertNotFound();
    }
}
