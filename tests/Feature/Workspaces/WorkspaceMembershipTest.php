<?php

declare(strict_types=1);

namespace Tests\Feature\Workspaces;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Notifications\WorkspaceInvitation;
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
        Notification::assertSentTo($invited, WorkspaceInvitation::class);
    }

    public function test_invited_user_can_accept_the_invitation_and_activate_their_account()
    {
        Notification::fake();

        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);

        $this->actingAs($admin->user)->post(route('workspaces.users.store', $workspace), [
            'email' => 'new.person@example.com',
            'name' => 'New Person',
            'role' => WorkspaceRole::User->value,
        ]);

        $invited = User::query()->where('email', 'new.person@example.com')->firstOrFail();

        $this->app['auth']->guard('web')->logout();

        Notification::assertSentTo($invited, WorkspaceInvitation::class, function ($notification) use ($invited) {
            $this->get(route('invitations.accept', ['token' => $notification->token, 'email' => $invited->email]))
                ->assertOk();

            $response = $this->post(route('password.update'), [
                'token' => $notification->token,
                'email' => $invited->email,
                'password' => 'Str0ng!Passw0rd',
                'password_confirmation' => 'Str0ng!Passw0rd',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login'));

            return true;
        });
    }

    public function test_admin_can_invite_a_brand_new_user_on_a_single_workspace_installation()
    {
        Notification::fake();

        // The admin exists before single-workspace mode is switched on, the
        // way a real installation gets there: seeded admin, then the flag.
        $adminUser = User::factory()->create();
        $workspace = Workspace::factory()->create();
        WorkspaceUser::factory()->for($workspace)->create([
            'user_id' => $adminUser->id,
            'role' => WorkspaceRole::Admin,
        ]);

        config(['archivum.multi_workspace_enabled' => false]);

        $response = $this->actingAs($adminUser)->post(route('workspaces.users.store', $workspace), [
            'email' => 'nova@example.com',
            'name' => 'Nova',
            'role' => WorkspaceRole::Admin->value,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $invited = User::query()->where('email', 'nova@example.com')->firstOrFail();

        $this->assertTrue($workspace->isAdmin($invited), 'The invited user must get the role they were invited with.');
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

    public function test_setting_a_member_to_the_role_they_already_have_is_a_no_op()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);

        // Submitting the form without touching the select must not be treated
        // as a demotion — which is what the last-admin guard below it would
        // otherwise refuse for the only admin.
        $response = $this->actingAs($admin->user)->patch(
            route('workspaces.users.update', [$workspace, $admin->user]),
            ['role' => WorkspaceRole::Admin->value],
        );

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertTrue($workspace->isAdmin($admin->user));
        $this->assertFalse($workspace->isAdmin($member->user));
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
