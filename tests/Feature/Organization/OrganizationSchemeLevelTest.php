<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Actions\Organization\CreateOrganizationNode;
use App\Actions\Organization\CreateOrganizationRule;
use App\Actions\Organization\CreateScheme;
use App\Enums\NodeValueStrategy;
use App\Enums\WorkspaceRole;
use App\Models\OrganizationScheme;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Support\Refusal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationSchemeLevelTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_admin_can_append_a_level_to_a_scheme_with_no_nodes()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $scheme = $this->createScheme($workspace);

        $response = $this->actingAs($admin->user)->post(route('organization.schemes.levels.store', $scheme), [
            'name' => 'Box',
            'key' => 'box',
            'value_strategy' => NodeValueStrategy::Sequential->value,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('organization_levels', ['scheme_id' => $scheme->id, 'key' => 'box', 'position' => 4]);
    }

    public function test_workspace_admin_can_append_a_level_to_a_scheme_that_already_has_nodes()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $scheme = $this->createScheme($workspace);
        app(CreateOrganizationNode::class)->handle($scheme->levels->first(), null, '001');

        $response = $this->actingAs($admin->user)->post(route('organization.schemes.levels.store', $scheme), [
            'name' => 'Box',
            'key' => 'box',
            'value_strategy' => NodeValueStrategy::Sequential->value,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('organization_levels', ['scheme_id' => $scheme->id, 'key' => 'box', 'position' => 4]);
    }

    public function test_appending_a_duplicate_level_key_is_rejected()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $scheme = $this->createScheme($workspace);

        $response = $this->actingAs($admin->user)->post(route('organization.schemes.levels.store', $scheme), [
            'name' => 'Cover 2',
            'key' => 'cover',
            'value_strategy' => NodeValueStrategy::Sequential->value,
        ]);

        $response->assertSessionHasErrors('key');
        $this->assertDatabaseMissing('organization_levels', ['scheme_id' => $scheme->id, 'name' => 'Cover 2']);
    }

    public function test_appending_an_alphabetical_level_with_capacity_greater_than_26_is_rejected()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $scheme = $this->createScheme($workspace);

        $response = $this->actingAs($admin->user)->post(route('organization.schemes.levels.store', $scheme), [
            'name' => 'Box',
            'key' => 'box',
            'capacity' => 27,
            'value_strategy' => NodeValueStrategy::Alphabetical->value,
        ]);

        $response->assertSessionHasErrors('capacity');
        $this->assertDatabaseMissing('organization_levels', ['scheme_id' => $scheme->id, 'key' => 'box']);
    }

    public function test_non_admin_member_cannot_append_a_level()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $scheme = $this->createScheme($workspace);

        $response = $this->actingAs($member->user)->post(route('organization.schemes.levels.store', $scheme), [
            'name' => 'Box',
            'key' => 'box',
            'value_strategy' => NodeValueStrategy::Sequential->value,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('organization_levels', ['scheme_id' => $scheme->id, 'key' => 'box']);
    }

    public function test_workspace_admin_can_turn_printable_labels_on_for_an_existing_level()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $scheme = $this->createScheme($workspace);
        $level = $scheme->levels()->orderBy('position')->first();

        // An archive whose levels already exist is the normal case, so the flag
        // has to be reachable after the scheme was created, not only during.
        $this->actingAs($admin->user)->patch(
            route('organization.schemes.levels.update', [$scheme, $level]),
            ['has_printable_label' => true],
        )->assertRedirect();

        $this->assertTrue($level->refresh()->has_printable_label);

        $this->actingAs($admin->user)->patch(
            route('organization.schemes.levels.update', [$scheme, $level]),
            ['has_printable_label' => false],
        )->assertRedirect();

        $this->assertFalse($level->refresh()->has_printable_label);
    }

    public function test_non_admin_member_cannot_turn_printable_labels_on()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $scheme = $this->createScheme($workspace);
        $level = $scheme->levels()->orderBy('position')->first();

        $this->actingAs($member->user)->patch(
            route('organization.schemes.levels.update', [$scheme, $level]),
            ['has_printable_label' => true],
        )->assertForbidden();

        $this->assertFalse($level->refresh()->has_printable_label);
    }

    public function test_workspace_admin_can_delete_the_last_level_when_it_has_no_nodes()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $scheme = $this->createScheme($workspace);
        $lastLevel = $scheme->levels()->orderBy('position')->get()->last();

        $response = $this->actingAs($admin->user)->delete(route('organization.schemes.levels.destroy', [$scheme, $lastLevel]));

        $response->assertRedirect();
        $this->assertDatabaseMissing('organization_levels', ['id' => $lastLevel->id]);
    }

    public function test_deleting_the_last_level_cascades_to_delete_rules_targeting_it()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $scheme = $this->createScheme($workspace);
        $lastLevel = $scheme->levels()->orderBy('position')->get()->last();
        $rule = app(CreateOrganizationRule::class)->handle($scheme, 'document_type', 'invoice', $lastLevel, 'INV');

        $response = $this->actingAs($admin->user)->delete(route('organization.schemes.levels.destroy', [$scheme, $lastLevel]));

        $response->assertRedirect();
        $this->assertDatabaseMissing('organization_rules', ['id' => $rule->id]);
    }

    public function test_deleting_a_level_that_is_not_last_is_rejected()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $scheme = $this->createScheme($workspace);
        $firstLevel = $scheme->levels()->orderBy('position')->first();

        $response = $this->actingAs($admin->user)->delete(route('organization.schemes.levels.destroy', [$scheme, $firstLevel]));

        $response->assertSessionHasErrors(Refusal::KEY);
        $this->assertDatabaseHas('organization_levels', ['id' => $firstLevel->id]);
    }

    public function test_deleting_the_last_level_is_rejected_when_it_has_nodes()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $scheme = $this->createScheme($workspace);
        $levels = $scheme->levels()->orderBy('position')->get();
        $action = app(CreateOrganizationNode::class);
        $cover = $action->handle($levels[0], null, '001');
        $letter = $action->handle($levels[1], $cover, 'A');
        $action->handle($levels[2], $letter);

        $lastLevel = $levels->last();

        $response = $this->actingAs($admin->user)->delete(route('organization.schemes.levels.destroy', [$scheme, $lastLevel]));

        $response->assertSessionHasErrors(Refusal::KEY);
        $this->assertDatabaseHas('organization_levels', ['id' => $lastLevel->id]);
    }

    public function test_non_admin_member_cannot_delete_a_level()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $scheme = $this->createScheme($workspace);
        $lastLevel = $scheme->levels()->orderBy('position')->get()->last();

        $response = $this->actingAs($member->user)->delete(route('organization.schemes.levels.destroy', [$scheme, $lastLevel]));

        $response->assertForbidden();
        $this->assertDatabaseHas('organization_levels', ['id' => $lastLevel->id]);
    }

    private function createScheme(Workspace $workspace): OrganizationScheme
    {
        return app(CreateScheme::class)->handle($workspace, 'Traditional Archive', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
            ['name' => 'Letter', 'key' => 'letter', 'value_strategy' => NodeValueStrategy::Manual],
            ['name' => 'Position', 'key' => 'position', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);
    }
}
