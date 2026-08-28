<?php

declare(strict_types=1);

namespace Tests\Feature\Workspaces;

use App\Actions\Organization\CreateScheme;
use App\Enums\NodeValueStrategy;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLimit;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkspaceSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_settings_with_no_scheme_yet()
    {
        $workspace = Workspace::factory()->create(['name' => 'Acme Archive']);
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);

        $this->actingAs($admin->user)
            ->get(route('workspaces.settings.show', $workspace))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('workspace/settings')
                ->where('workspace.name', 'Acme Archive')
                ->where('scheme', null)
                ->where('instance.multi_workspace_enabled', true),
            );
    }

    public function test_admin_sees_the_current_scheme_when_one_exists()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $scheme = app(CreateScheme::class)->handle($workspace, 'Traditional Archive', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);

        $this->actingAs($admin->user)
            ->get(route('workspaces.settings.show', $workspace))
            ->assertInertia(fn (Assert $page) => $page
                ->where('scheme.id', $scheme->id)
                ->where('scheme.name', 'Traditional Archive'),
            );
    }

    public function test_workspace_admin_does_not_see_limits()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);

        $this->actingAs($admin->user)
            ->get(route('workspaces.settings.show', $workspace))
            ->assertInertia(fn (Assert $page) => $page
                ->where('isPlatformAdmin', false)
                ->where('limits', null),
            );
    }

    public function test_platform_admin_sees_the_configured_limits()
    {
        $workspace = Workspace::factory()->create();
        WorkspaceLimit::factory()->for($workspace)->create(['users' => 5, 'storage_bytes' => null]);
        $platformAdmin = User::factory()->create(['is_platform_admin' => true]);

        $this->actingAs($platformAdmin)
            ->get(route('workspaces.settings.show', $workspace))
            ->assertInertia(fn (Assert $page) => $page
                ->where('isPlatformAdmin', true)
                ->where('limits.users', 5)
                ->where('limits.storage_bytes', null),
            );
    }

    public function test_non_admin_member_cannot_view_settings()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);

        $this->actingAs($member->user)
            ->get(route('workspaces.settings.show', $workspace))
            ->assertForbidden();
    }

    public function test_outsider_cannot_view_settings()
    {
        $workspace = Workspace::factory()->create();
        $outsider = WorkspaceUser::factory()->create(['role' => WorkspaceRole::Admin]);

        $this->actingAs($outsider->user)
            ->get(route('workspaces.settings.show', $workspace))
            ->assertForbidden();
    }
}
