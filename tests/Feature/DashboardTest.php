<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Organization\CreateScheme;
use App\Enums\NodeValueStrategy;
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
                ->where('documentsCount', null)
                ->where('organizationSchemeId', null),
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

    public function test_dashboard_shares_the_workspace_organization_scheme_id_when_one_exists()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $scheme = app(CreateScheme::class)->handle($workspace, 'Traditional Archive', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);

        $this->actingAs($member->user)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('organizationSchemeId', $scheme->id),
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

    public function test_dashboard_reports_workspace_stats_and_recent_documents()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        Document::factory()->for($workspace)->create(['title' => 'Older', 'updated_at' => now()->subDay()]);
        Document::factory()->for($workspace)->create(['title' => 'Newest', 'updated_at' => now()]);

        $this->actingAs($member->user)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('stats.documents', 2)
                ->where('stats.users', 1)
                ->has('recentDocuments', 2)
                ->where('recentDocuments.0.title', 'Newest')
                ->where('recentDocuments.1.title', 'Older'),
            );
    }

    public function test_dashboard_caps_recent_documents_at_five()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        Document::factory()->for($workspace)->count(8)->create();

        $this->actingAs($member->user)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->has('recentDocuments', 5));
    }

    public function test_dashboard_never_leaks_another_workspaces_documents()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        Document::factory()->for($workspace)->create(['title' => 'Mine']);
        Document::factory()->create(['title' => 'Theirs']);

        $this->actingAs($member->user)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('recentDocuments', 1)
                ->where('recentDocuments.0.title', 'Mine'),
            );
    }

    public function test_dashboard_renders_the_onboarding_state_without_a_workspace()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('stats', null)
                ->where('recentDocuments', [])
                ->where('recentActivity', []),
            );
    }

    public function test_dashboard_shares_admin_status_for_a_platform_admin_managing_a_foreign_workspace()
    {
        $workspace = Workspace::factory()->create();
        $platformAdmin = User::factory()->create(['is_platform_admin' => true]);

        $this->actingAs($platformAdmin)->post(route('workspaces.switch', $workspace))->assertRedirect();

        $this->actingAs($platformAdmin)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('workspace.id', $workspace->id)
                ->where('workspaces', [])
                ->where('isWorkspaceAdmin', true),
            );
    }
}
