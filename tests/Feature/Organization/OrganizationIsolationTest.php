<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Actions\Organization\CreateOrganizationRule;
use App\Actions\Organization\CreateScheme;
use App\Enums\NodeValueStrategy;
use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_cannot_create_a_scheme_in_a_workspace_they_do_not_belong_to()
    {
        $outsider = WorkspaceUser::factory()->create(['role' => WorkspaceRole::Admin]);
        $otherWorkspace = Workspace::factory()->create();

        $response = $this->actingAs($outsider->user)->post(route('organization.schemes.store', $otherWorkspace), [
            'name' => 'Hijacked',
            'levels' => [
                ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential->value],
            ],
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('organization_schemes', ['name' => 'Hijacked']);
    }

    public function test_user_cannot_create_nodes_or_rules_on_a_scheme_they_do_not_belong_to()
    {
        $outsider = WorkspaceUser::factory()->create(['role' => WorkspaceRole::Admin]);
        $otherWorkspace = Workspace::factory()->create();
        $otherScheme = app(CreateScheme::class)->handle($otherWorkspace, 'Traditional Archive', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);
        $level = $otherScheme->levels->first();

        $this->actingAs($outsider->user)
            ->post(route('organization.schemes.nodes.store', $otherScheme), ['level_id' => $level->id])
            ->assertForbidden();

        $this->actingAs($outsider->user)
            ->post(route('organization.schemes.rules.store', $otherScheme), [
                'matcher_key' => 'document_type',
                'matcher_value' => 'invoice',
                'target_level_id' => $level->id,
                'preferred_value' => 'A',
            ])
            ->assertForbidden();
    }

    public function test_a_rule_belonging_to_a_different_scheme_than_the_url_returns_not_found()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);

        $schemeA = app(CreateScheme::class)->handle($workspace, 'Scheme A', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);
        $schemeB = app(CreateScheme::class)->handle($workspace, 'Scheme B', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);

        $ruleOnSchemeA = app(CreateOrganizationRule::class)->handle(
            $schemeA,
            'document_type',
            'invoice',
            $schemeA->levels->first(),
            'A',
        );

        $response = $this->actingAs($admin->user)->delete(
            route('organization.schemes.rules.destroy', [$schemeB, $ruleOnSchemeA]),
        );

        $response->assertNotFound();
    }
}
