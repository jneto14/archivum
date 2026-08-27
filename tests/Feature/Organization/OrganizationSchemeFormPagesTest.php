<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Actions\Organization\CreateScheme;
use App\Enums\NodeValueStrategy;
use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrganizationSchemeFormPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_the_create_form()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);

        $this->actingAs($admin->user)
            ->get(route('organization.schemes.create', $workspace))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('organization/form')
                ->where('scheme', null),
            );
    }

    public function test_non_admin_member_cannot_view_the_create_form()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);

        $this->actingAs($member->user)
            ->get(route('organization.schemes.create', $workspace))
            ->assertForbidden();
    }

    public function test_admin_can_view_the_edit_form()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $scheme = app(CreateScheme::class)->handle($workspace, 'Traditional Archive', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);

        $this->actingAs($admin->user)
            ->get(route('organization.schemes.edit', $scheme))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('organization/form')
                ->where('scheme.id', $scheme->id),
            );
    }

    public function test_non_admin_member_cannot_view_the_edit_form()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $scheme = app(CreateScheme::class)->handle($workspace, 'Traditional Archive', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);

        $this->actingAs($member->user)
            ->get(route('organization.schemes.edit', $scheme))
            ->assertForbidden();
    }
}
