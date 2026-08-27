<?php

declare(strict_types=1);

namespace Tests\Feature\Workspaces;

use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkspaceUserIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_members()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);

        $this->actingAs($admin->user)
            ->get(route('workspaces.users.index', $workspace))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('workspace/users')
                ->has('members', 2)
                ->where('members.0.email', $admin->user->email)
                ->where('members.0.role', 'admin')
                ->where('members.1.email', $member->user->email)
                ->where('members.1.role', 'user'),
            );
    }

    public function test_non_admin_member_cannot_list_members()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);

        $this->actingAs($member->user)
            ->get(route('workspaces.users.index', $workspace))
            ->assertForbidden();
    }

    public function test_outsider_cannot_list_members()
    {
        $workspace = Workspace::factory()->create();
        $outsider = WorkspaceUser::factory()->create(['role' => WorkspaceRole::Admin]);

        $this->actingAs($outsider->user)
            ->get(route('workspaces.users.index', $workspace))
            ->assertForbidden();
    }
}
