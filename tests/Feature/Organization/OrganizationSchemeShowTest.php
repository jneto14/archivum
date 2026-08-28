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
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrganizationSchemeShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_view_a_scheme_with_its_levels_and_rules()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $scheme = app(CreateScheme::class)->handle($workspace, 'Traditional Archive', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);

        $this->actingAs($member->user)
            ->get(route('organization.schemes.show', $scheme))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('organization/show')
                ->where('scheme.name', 'Traditional Archive')
                ->has('scheme.levels', 1)
                ->where('scheme.levels.0.name', 'Cover')
                ->where('canManage', false),
            );
    }

    public function test_outsider_cannot_view_a_scheme()
    {
        $workspace = Workspace::factory()->create();
        $outsider = WorkspaceUser::factory()->create(['role' => WorkspaceRole::Admin]);
        $scheme = app(CreateScheme::class)->handle($workspace, 'Traditional Archive', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);

        $this->actingAs($outsider->user)
            ->get(route('organization.schemes.show', $scheme))
            ->assertForbidden();
    }

    public function test_resulting_path_uses_the_first_matching_rule_per_level()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $scheme = app(CreateScheme::class)->handle($workspace, 'Traditional Archive', [
            ['name' => 'Year', 'key' => 'year', 'value_strategy' => NodeValueStrategy::Manual],
            ['name' => 'Position', 'key' => 'position', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);
        $yearLevel = $scheme->levels->first();
        app(CreateOrganizationRule::class)->handle($scheme, 'document_type', 'invoice', $yearLevel, '2026');

        $this->actingAs($member->user)
            ->get(route('organization.schemes.show', $scheme))
            ->assertInertia(fn (Assert $page) => $page
                ->where('resultingPath.levels.0.sample', '2026')
                ->where('resultingPath.levels.1.sample', null)
                ->where('resultingPath.path', '2026-···'),
            );
    }
}
