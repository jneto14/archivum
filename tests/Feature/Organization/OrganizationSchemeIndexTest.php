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

class OrganizationSchemeIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_sees_only_their_workspaces_schemes()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        app(CreateScheme::class)->handle($workspace, 'Mine', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);
        app(CreateScheme::class)->handle(Workspace::factory()->create(), 'Theirs', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);

        $this->actingAs($member->user)
            ->get(route('organization.schemes.index', $workspace))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('organization/index')
                ->has('schemes', 1)
                ->where('schemes.0.name', 'Mine')
                ->where('schemes.0.levels_count', 1)
                ->where('canManage', false),
            );
    }

    public function test_non_member_cannot_view_the_index()
    {
        $workspace = Workspace::factory()->create();
        $outsider = WorkspaceUser::factory()->create(['role' => WorkspaceRole::Admin]);

        $this->actingAs($outsider->user)
            ->get(route('organization.schemes.index', $workspace))
            ->assertForbidden();
    }
}
