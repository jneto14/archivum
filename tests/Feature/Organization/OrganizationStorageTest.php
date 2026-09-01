<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Actions\Documents\CreateDocument;
use App\Actions\Documents\MoveDocument;
use App\Actions\Organization\CreateOrganizationNode;
use App\Actions\Organization\CreateScheme;
use App\Actions\Organization\DeleteOrganizationLevel;
use App\Enums\NodeValueStrategy;
use App\Enums\WorkspaceRole;
use App\Models\DocumentType;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrganizationStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_browse_the_node_tree()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $scheme = app(CreateScheme::class)->handle($workspace, 'Traditional Archive', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
            ['name' => 'Position', 'key' => 'position', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);
        $cover = app(CreateOrganizationNode::class)->handle($scheme->levels->first(), null);
        app(CreateOrganizationNode::class)->handle($scheme->levels->last(), $cover);

        $this->actingAs($member->user)
            ->get(route('organization.schemes.storage', $scheme))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('organization/storage')
                ->where('scheme.name', 'Traditional Archive')
                ->has('levels', 2)
                ->has('tree', 1)
                ->where('tree.0.value', $cover->value)
                ->has('tree.0.children', 1)
                ->where('canManage', false),
            );
    }

    public function test_outsider_cannot_browse_the_node_tree()
    {
        $workspace = Workspace::factory()->create();
        $outsider = WorkspaceUser::factory()->create(['role' => WorkspaceRole::Admin]);
        $scheme = app(CreateScheme::class)->handle($workspace, 'Traditional Archive', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);

        $this->actingAs($outsider->user)
            ->get(route('organization.schemes.storage', $scheme))
            ->assertForbidden();
    }

    public function test_leaf_level_nodes_report_their_current_document_count()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $scheme = app(CreateScheme::class)->handle($workspace, 'Traditional Archive', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);
        $node = app(CreateOrganizationNode::class)->handle($scheme->levels->first(), null);
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $admin->user, $type, 'Filed', null, null);
        app(MoveDocument::class)->handle($document, $node);

        $this->actingAs($admin->user)
            ->get(route('organization.schemes.storage', $scheme))
            ->assertInertia(fn (Assert $page) => $page
                ->where('tree.0.documents_count', 1)
                ->where('canManage', true),
            );
    }

    public function test_the_storage_page_holds_up_for_a_scheme_whose_levels_were_all_removed()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $scheme = app(CreateScheme::class)->handle($workspace, 'Traditional Archive', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);

        // Nothing stops the last level being deleted while it is still empty,
        // so the page has to render an empty tree rather than fail.
        app(DeleteOrganizationLevel::class)->handle($scheme->levels->first());

        $this->actingAs($admin->user)
            ->get(route('organization.schemes.storage', $scheme))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('organization/storage')
                ->has('levels', 0)
                ->has('tree', 0),
            );
    }
}
