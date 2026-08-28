<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Actions\Documents\CreateDocument;
use App\Actions\Documents\MoveDocument;
use App\Actions\Organization\CreateOrganizationNode;
use App\Actions\Organization\CreateScheme;
use App\Enums\NodeValueStrategy;
use App\Enums\WorkspaceRole;
use App\Models\DocumentType;
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

    public function test_leaf_level_nodes_report_their_current_document_count()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $scheme = app(CreateScheme::class)->handle($workspace, 'Traditional Archive', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);
        $level = $scheme->levels->first();
        $node = app(CreateOrganizationNode::class)->handle($level, null);
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $admin->user, $type, 'Filed', null, null);
        app(MoveDocument::class)->handle($document, $node);

        $this->actingAs($admin->user)
            ->get(route('organization.schemes.show', $scheme))
            ->assertInertia(fn (Assert $page) => $page
                ->where('scheme.levels.0.nodes.0.documents_count', 1),
            );
    }
}
