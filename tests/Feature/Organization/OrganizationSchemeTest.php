<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Enums\NodeValueStrategy;
use App\Enums\WorkspaceRole;
use App\Models\OrganizationScheme;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationSchemeTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_admin_can_create_a_scheme_with_ordered_levels()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);

        $response = $this->actingAs($admin->user)->post(route('organization.schemes.store', $workspace), [
            'name' => 'Traditional Archive',
            'levels' => [
                ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential->value],
                ['name' => 'Letter', 'key' => 'letter', 'value_strategy' => NodeValueStrategy::Manual->value],
                ['name' => 'Position', 'key' => 'position', 'value_strategy' => NodeValueStrategy::Sequential->value],
            ],
        ]);

        $scheme = OrganizationScheme::query()->where('name', 'Traditional Archive')->firstOrFail();

        $response->assertRedirect(route('organization.schemes.show', $scheme));

        $this->assertSame(['Cover', 'Letter', 'Position'], $scheme->levels()->pluck('name')->all());
        $this->assertSame([1, 2, 3], $scheme->levels()->pluck('position')->all());
    }

    public function test_duplicate_level_keys_are_rejected()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);

        $response = $this->actingAs($admin->user)->post(route('organization.schemes.store', $workspace), [
            'name' => 'Traditional Archive',
            'levels' => [
                ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential->value],
                ['name' => 'Cover 2', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential->value],
            ],
        ]);

        $response->assertSessionHasErrors();
        $this->assertDatabaseMissing('organization_schemes', ['name' => 'Traditional Archive']);
    }

    public function test_a_second_scheme_cannot_be_created_in_a_workspace_that_already_has_one()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        OrganizationScheme::factory()->for($workspace)->create();

        $response = $this->actingAs($admin->user)->post(route('organization.schemes.store', $workspace), [
            'name' => 'Second Scheme',
            'levels' => [
                ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential->value],
            ],
        ]);

        $response->assertSessionHasErrors('name');
        $this->assertDatabaseMissing('organization_schemes', ['name' => 'Second Scheme']);
    }

    public function test_an_alphabetical_level_with_no_capacity_defaults_to_26()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);

        $this->actingAs($admin->user)->post(route('organization.schemes.store', $workspace), [
            'name' => 'Traditional Archive',
            'levels' => [
                ['name' => 'Letter', 'key' => 'letter', 'value_strategy' => NodeValueStrategy::Alphabetical->value],
            ],
        ]);

        $scheme = OrganizationScheme::query()->where('name', 'Traditional Archive')->firstOrFail();

        $this->assertSame(26, $scheme->levels->first()->capacity);
    }

    public function test_an_alphabetical_level_capacity_greater_than_26_is_rejected()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);

        $response = $this->actingAs($admin->user)->post(route('organization.schemes.store', $workspace), [
            'name' => 'Traditional Archive',
            'levels' => [
                ['name' => 'Letter', 'key' => 'letter', 'capacity' => 27, 'value_strategy' => NodeValueStrategy::Alphabetical->value],
            ],
        ]);

        $response->assertSessionHasErrors('levels');
        $this->assertDatabaseMissing('organization_schemes', ['name' => 'Traditional Archive']);
    }

    public function test_a_non_alphabetical_level_with_no_capacity_stays_unlimited()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);

        $this->actingAs($admin->user)->post(route('organization.schemes.store', $workspace), [
            'name' => 'Traditional Archive',
            'levels' => [
                ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential->value],
            ],
        ]);

        $scheme = OrganizationScheme::query()->where('name', 'Traditional Archive')->firstOrFail();

        $this->assertNull($scheme->levels->first()->capacity);
    }

    public function test_non_admin_member_cannot_create_a_scheme()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);

        $response = $this->actingAs($member->user)->post(route('organization.schemes.store', $workspace), [
            'name' => 'Traditional Archive',
            'levels' => [
                ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential->value],
            ],
        ]);

        $response->assertForbidden();
    }

    public function test_workspace_admin_can_rename_a_scheme()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $scheme = OrganizationScheme::factory()->for($workspace)->create();

        $response = $this->actingAs($admin->user)->patch(route('organization.schemes.update', $scheme), [
            'name' => 'Renamed',
        ]);

        $response->assertRedirect();
        $this->assertSame('Renamed', $scheme->fresh()->name);
    }

    public function test_non_admin_member_cannot_rename_a_scheme()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $scheme = OrganizationScheme::factory()->for($workspace)->create();

        $response = $this->actingAs($member->user)->patch(route('organization.schemes.update', $scheme), [
            'name' => 'Renamed',
        ]);

        $response->assertForbidden();
    }

    public function test_workspace_members_can_view_a_scheme_but_only_admins_can_manage_it()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $scheme = OrganizationScheme::factory()->for($workspace)->create();

        $this->assertTrue($admin->user->can('view', $scheme));
        $this->assertTrue($member->user->can('view', $scheme));
        $this->assertTrue($admin->user->can('update', $scheme));
        $this->assertFalse($member->user->can('update', $scheme));
    }
}
