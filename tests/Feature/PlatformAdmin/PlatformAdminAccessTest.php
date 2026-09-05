<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Actions\Organization\CreateScheme;
use App\Enums\NodeValueStrategy;
use App\Enums\WorkspaceRole;
use App\Models\DocumentType;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PlatformAdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_view_and_update_a_workspace_they_do_not_belong_to()
    {
        $workspace = Workspace::factory()->create(['name' => 'Foreign Co']);
        $platformAdmin = User::factory()->create(['is_platform_admin' => true]);

        $this->actingAs($platformAdmin)
            ->get(route('workspaces.settings.show', $workspace))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('workspace/settings')
                ->where('isPlatformAdmin', true),
            );

        $this->actingAs($platformAdmin)
            ->patch(route('workspaces.update', $workspace), ['name' => 'Renamed by platform admin'])
            ->assertRedirect();

        $this->assertSame('Renamed by platform admin', $workspace->fresh()->name);
    }

    public function test_platform_admin_can_delete_a_workspace_they_do_not_belong_to()
    {
        Workspace::factory()->create();
        $workspace = Workspace::factory()->create();
        $platformAdmin = User::factory()->create(['is_platform_admin' => true]);

        $this->actingAs($platformAdmin)
            ->delete(route('workspaces.destroy', $workspace))
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('workspaces', ['id' => $workspace->id]);
    }

    public function test_platform_admin_can_manage_members_of_a_workspace_they_do_not_belong_to()
    {
        $workspace = Workspace::factory()->create();
        $platformAdmin = User::factory()->create(['is_platform_admin' => true]);
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $invitee = User::factory()->create();

        $this->actingAs($platformAdmin)
            ->post(route('workspaces.users.store', $workspace), [
                'email' => $invitee->email,
                'role' => WorkspaceRole::User->value,
            ])
            ->assertRedirect();
        $this->assertTrue($workspace->isMember($invitee));

        $this->actingAs($platformAdmin)
            ->patch(route('workspaces.users.update', [$workspace, $member->user]), ['role' => WorkspaceRole::Admin->value])
            ->assertRedirect();
        $this->assertTrue($workspace->isAdmin($member->user));

        $this->actingAs($platformAdmin)
            ->delete(route('workspaces.users.destroy', [$workspace, $invitee]))
            ->assertRedirect();
        $this->assertFalse($workspace->isMember($invitee));
    }

    public function test_platform_admin_can_manage_document_types_of_a_workspace_they_do_not_belong_to()
    {
        $workspace = Workspace::factory()->create();
        $platformAdmin = User::factory()->create(['is_platform_admin' => true]);

        $this->actingAs($platformAdmin)
            ->post(route('document-types.store', $workspace), ['name' => 'Invoice', 'key' => 'invoice'])
            ->assertRedirect();

        $type = DocumentType::query()->where('workspace_id', $workspace->id)->firstOrFail();

        $this->actingAs($platformAdmin)
            ->patch(route('document-types.update', $type), ['name' => 'Receipt', 'key' => 'receipt'])
            ->assertRedirect();
        $this->assertSame('Receipt', $type->fresh()->name);

        $this->actingAs($platformAdmin)
            ->delete(route('document-types.destroy', $type))
            ->assertRedirect();
        $this->assertDatabaseMissing('document_types', ['id' => $type->id]);
    }

    public function test_platform_admin_can_manage_the_organization_scheme_of_a_workspace_they_do_not_belong_to()
    {
        $workspace = Workspace::factory()->create();
        $platformAdmin = User::factory()->create(['is_platform_admin' => true]);
        $scheme = app(CreateScheme::class)->handle($workspace, 'Traditional Archive', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);

        $this->actingAs($platformAdmin)
            ->patch(route('organization.schemes.update', $scheme), ['name' => 'Renamed Scheme'])
            ->assertRedirect();

        $this->assertSame('Renamed Scheme', $scheme->fresh()->name);
    }

    public function test_non_platform_admin_is_still_rejected_from_a_workspace_they_do_not_belong_to()
    {
        $workspace = Workspace::factory()->create();
        $outsider = WorkspaceUser::factory()->create(['role' => WorkspaceRole::Admin]);

        $this->actingAs($outsider->user)
            ->get(route('workspaces.settings.show', $workspace))
            ->assertForbidden();
    }

    public function test_workspaces_index_lists_every_workspace_for_a_platform_admin()
    {
        Workspace::factory()->count(2)->create();
        $platformAdmin = User::factory()->create(['is_platform_admin' => true]);

        $this->actingAs($platformAdmin)
            ->get(route('workspaces.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('workspace/index')
                ->has('workspaces', 2),
            );
    }

    public function test_workspaces_index_can_be_ordered_by_how_many_members_each_has()
    {
        $crowded = Workspace::factory()->create(['name' => 'Aardvark']);
        WorkspaceUser::factory()->for($crowded)->count(3)->create(['role' => WorkspaceRole::User]);
        Workspace::factory()->create(['name' => 'Zebra']);

        $platformAdmin = User::factory()->create(['is_platform_admin' => true]);

        $this->actingAs($platformAdmin)
            ->get(route('workspaces.index', ['sort' => 'users_count', 'direction' => 'desc']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('sort.key', 'users_count')
                ->where('workspaces.0.name', 'Aardvark')
                ->where('workspaces.0.usersCount', 3)
                ->where('workspaces.1.name', 'Zebra'),
            );
    }

    public function test_workspaces_index_is_forbidden_for_a_non_platform_admin()
    {
        $member = WorkspaceUser::factory()->create(['role' => WorkspaceRole::Admin]);

        $this->actingAs($member->user)
            ->get(route('workspaces.index'))
            ->assertForbidden();
    }

    public function test_workspaces_index_does_not_exist_in_single_workspace_mode()
    {
        config(['archivum.multi_workspace_enabled' => false]);
        $platformAdmin = User::factory()->create(['is_platform_admin' => true]);

        $this->actingAs($platformAdmin)
            ->get(route('workspaces.index'))
            ->assertNotFound();
    }
}
