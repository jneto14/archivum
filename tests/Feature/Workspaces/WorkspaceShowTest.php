<?php

declare(strict_types=1);

namespace Tests\Feature\Workspaces;

use App\Actions\Documents\CreateDocument;
use App\Enums\WorkspaceRole;
use App\Models\DocumentType;
use App\Models\Workspace;
use App\Models\WorkspaceLimit;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkspaceShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_view_the_workspace_overview()
    {
        $workspace = Workspace::factory()->create(['name' => 'Acme Archive']);
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);

        $this->actingAs($member->user)
            ->get(route('workspaces.show', $workspace))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('workspace/show')
                ->where('workspace.name', 'Acme Archive')
                ->where('isAdmin', false)
                ->where('usage', null),
            );
    }

    public function test_admin_sees_usage_figures()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $type = DocumentType::factory()->for($workspace)->create();
        app(CreateDocument::class)->handle($workspace, $admin->user, $type, 'Invoice', null, null);
        WorkspaceLimit::factory()->for($workspace)->create(['documents' => 100, 'users' => null]);

        $this->actingAs($admin->user)
            ->get(route('workspaces.show', $workspace))
            ->assertInertia(fn (Assert $page) => $page
                ->where('isAdmin', true)
                ->where('usage.documents.used', 1)
                ->where('usage.documents.limit', 100)
                ->where('usage.users.limit', null),
            );
    }

    public function test_outsider_cannot_view_the_workspace()
    {
        $workspace = Workspace::factory()->create();
        $outsider = WorkspaceUser::factory()->create(['role' => WorkspaceRole::Admin]);

        $this->actingAs($outsider->user)
            ->get(route('workspaces.show', $workspace))
            ->assertForbidden();
    }
}
