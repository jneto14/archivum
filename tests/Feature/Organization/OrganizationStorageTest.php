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
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\OrganizationScheme;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use Inertia\Inertia;
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
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Manual],
            ['name' => 'Position', 'key' => 'position', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);
        $cover = app(CreateOrganizationNode::class)->handle($scheme->levels->first(), null, '001');
        app(CreateOrganizationNode::class)->handle($scheme->levels->last(), $cover);

        $this->actingAs($member->user)
            ->get(route('organization.schemes.storage', $scheme))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('organization/storage')
                ->where('scheme.name', 'Traditional Archive')
                ->has('levels', 2)
                // The add-node dialog asks for a value outright at a Manual
                // level, and offers to generate one everywhere else.
                ->where('levels.0.value_strategy', 'manual')
                ->where('levels.1.value_strategy', 'sequential')
                ->has('tree', 1)
                ->where('tree.0.value', $cover->value)
                ->has('tree.0.children', 1)
                ->where('canManage', false),
            );
    }

    public function test_a_locations_contents_come_with_the_page_only_when_one_is_named()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $scheme = app(CreateScheme::class)->handle($workspace, 'Traditional Archive', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);
        $node = app(CreateOrganizationNode::class)->handle($scheme->levels->first(), null, '001');

        $filed = Document::factory()->for($workspace)->create(['title' => 'On the shelf']);
        app(MoveDocument::class)->handle($filed, $node);
        Document::factory()->for($workspace)->create(['title' => 'Filed nowhere']);

        // Nothing is loaded for a page nobody asked a location of, and a label's
        // QR code lands here with `?node=` already in the URL, so the sheet
        // opens on arrival rather than after a second round trip.
        $this->actingAs($member->user)
            ->get(route('organization.schemes.storage', $scheme))
            ->assertInertia(fn (Assert $page) => $page->where('nodeDocuments', null));

        $this->actingAs($member->user)
            ->get(route('organization.schemes.storage', ['scheme' => $scheme, 'node' => $node->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('nodeDocuments.node.path', '001')
                ->where('nodeDocuments.total', 1),
            );

        $this->partialReload($member, $scheme, $node->id)
            ->assertOk()
            ->assertJsonPath('props.nodeDocuments.node.path', '001')
            ->assertJsonPath('props.nodeDocuments.total', 1)
            ->assertJsonCount(1, 'props.nodeDocuments.documents')
            ->assertJsonPath('props.nodeDocuments.documents.0.id', $filed->id);
    }

    public function test_a_location_from_another_scheme_has_no_contents_to_show()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $scheme = app(CreateScheme::class)->handle($workspace, 'Traditional Archive', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);

        $foreignScheme = app(CreateScheme::class)->handle(Workspace::factory()->create(), 'Foreign', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);
        $foreignNode = app(CreateOrganizationNode::class)->handle($foreignScheme->levels->first(), null, '001');

        // The page is loaded first, as the panel's own reload always is: the
        // asset version the partial reload has to send is settled by then.
        $this->actingAs($member->user)
            ->get(route('organization.schemes.storage', $scheme))
            ->assertOk();

        $this->partialReload($member, $scheme, $foreignNode->id)
            ->assertOk()
            ->assertJsonPath('props.nodeDocuments', null);
    }

    /**
     * Ask the storage page for one location's contents, the way the panel does.
     *
     * @param WorkspaceUser $actor The workspace member making the request.
     * @param OrganizationScheme $scheme The scheme whose page is reloaded.
     * @param string $nodeId The location to look inside.
     *
     * @return TestResponse<Response> The partial-reload response.
     */
    private function partialReload(WorkspaceUser $actor, OrganizationScheme $scheme, string $nodeId): TestResponse
    {
        return $this->actingAs($actor->user)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (string) Inertia::getVersion(),
                'X-Inertia-Partial-Component' => 'organization/storage',
                'X-Inertia-Partial-Data' => 'nodeDocuments',
            ])
            ->get(route('organization.schemes.storage', ['scheme' => $scheme, 'node' => $nodeId]));
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
