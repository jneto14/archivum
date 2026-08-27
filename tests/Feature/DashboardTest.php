<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_dashboard_shares_no_workspace_for_a_user_with_no_memberships()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('workspace', null)
                ->where('workspaces', [])
                ->where('isWorkspaceAdmin', false)
                ->where('documentsCount', null),
            );
    }

    public function test_dashboard_shares_the_current_workspace_for_a_member()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        Document::factory()->for($workspace)->count(2)->create();

        $this->actingAs($member->user)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('workspace.id', $workspace->id)
                ->where('workspace.name', $workspace->name)
                ->where('workspaces.0.id', $workspace->id)
                ->where('workspaces.0.role', WorkspaceRole::User->value)
                ->where('isWorkspaceAdmin', false)
                ->where('documentsCount', 2),
            );
    }

    public function test_dashboard_shares_admin_status_for_an_admin_member()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);

        $this->actingAs($admin->user)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('isWorkspaceAdmin', true));
    }
}
