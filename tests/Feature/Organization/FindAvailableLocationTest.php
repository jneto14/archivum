<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Actions\Documents\CreateDocument;
use App\Actions\Documents\MoveDocument;
use App\Actions\Organization\CreateOrganizationRule;
use App\Actions\Organization\CreateScheme;
use App\Actions\Organization\FindAvailableLocation;
use App\Enums\NodeValueStrategy;
use App\Models\DocumentType;
use App\Models\OrganizationNode;
use App\Models\OrganizationScheme;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FindAvailableLocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_first_leaf_on_an_empty_scheme()
    {
        $scheme = $this->createScheme();

        $node = app(FindAvailableLocation::class)->handle($scheme);

        $this->assertSame('001-001', $node->path());
    }

    public function test_it_reuses_a_leaf_that_still_has_room_for_a_document()
    {
        $workspace = Workspace::factory()->create();
        $scheme = $this->createScheme($workspace, positionCapacity: 5);

        $first = app(FindAvailableLocation::class)->handle($scheme);
        $this->fileDocument($workspace, $first);

        $second = app(FindAvailableLocation::class)->handle($scheme);

        // The position holds one of its five documents, so the next document
        // goes in beside it rather than opening a position of its own.
        $this->assertTrue($second->is($first));
        $this->assertSame('001-001', $second->path());
    }

    public function test_a_leaf_without_a_capacity_is_never_reused()
    {
        $workspace = Workspace::factory()->create();
        $scheme = $this->createScheme($workspace);

        $first = app(FindAvailableLocation::class)->handle($scheme);
        $this->fileDocument($workspace, $first);

        $second = app(FindAvailableLocation::class)->handle($scheme);

        // Nothing says how many documents fit in a position, so filing into an
        // occupied one would be a guess. Each document opens its own instead.
        $this->assertSame('001-002', $second->path());
    }

    public function test_a_full_branch_triggers_a_new_branch()
    {
        $workspace = Workspace::factory()->create();
        $scheme = $this->createScheme($workspace, positionCapacity: 1);

        $first = app(FindAvailableLocation::class)->handle($scheme);
        $this->fileDocument($workspace, $first);

        $second = app(FindAvailableLocation::class)->handle($scheme);

        $this->assertSame('001-001', $first->path());
        $this->assertSame('002-001', $second->path());
    }

    public function test_a_manual_level_with_no_existing_node_and_no_rule_throws()
    {
        $scheme = app(CreateScheme::class)->handle(Workspace::factory()->create(), 'Traditional Archive', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
            ['name' => 'Letter', 'key' => 'letter', 'value_strategy' => NodeValueStrategy::Manual],
        ]);

        $this->expectException(ValidationException::class);

        app(FindAvailableLocation::class)->handle($scheme);
    }

    public function test_a_rules_preferred_branch_is_created_when_it_does_not_exist_yet()
    {
        $scheme = app(CreateScheme::class)->handle(Workspace::factory()->create(), 'Traditional Archive', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Manual],
            ['name' => 'Position', 'key' => 'position', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);
        $cover = $scheme->levels()->where('key', 'cover')->firstOrFail();

        app(CreateOrganizationRule::class)->handle($scheme, 'document_type', 'invoice', $cover, 'FACTURAS');

        $node = app(FindAvailableLocation::class)->handle($scheme, ['document_type' => 'invoice']);

        // The rule names a branch that has never been filed into before, so the
        // action has to open it rather than fall back to some other cover.
        $this->assertSame('FACTURAS-001', $node->path());
    }

    public function test_preview_reports_where_a_document_would_go_without_creating_anything()
    {
        $scheme = $this->createScheme();

        $preview = app(FindAvailableLocation::class)->preview($scheme);

        $this->assertNull($preview['node']);
        $this->assertSame('001-001', $preview['path']);
        $this->assertSame('001', $preview['value']);
        $this->assertSame(0, OrganizationNode::query()->count());
    }

    public function test_preview_returns_the_existing_node_it_would_reuse()
    {
        $workspace = Workspace::factory()->create();
        $scheme = $this->createScheme($workspace, positionCapacity: 5);
        $existing = app(FindAvailableLocation::class)->handle($scheme);

        $preview = app(FindAvailableLocation::class)->preview($scheme);

        $this->assertNotNull($preview['node']);
        $this->assertTrue($preview['node']->is($existing));
        $this->assertSame(1, OrganizationNode::query()->where('level_id', $existing->level_id)->count());
    }

    public function test_preview_projects_a_branch_a_rule_names_but_that_does_not_exist_yet()
    {
        $scheme = app(CreateScheme::class)->handle(Workspace::factory()->create(), 'Traditional Archive', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Manual],
            ['name' => 'Position', 'key' => 'position', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);
        $cover = $scheme->levels()->where('key', 'cover')->firstOrFail();

        app(CreateOrganizationRule::class)->handle($scheme, 'document_type', 'invoice', $cover, 'FACTURAS');

        $preview = app(FindAvailableLocation::class)->preview($scheme, ['document_type' => 'invoice']);

        $this->assertNull($preview['node']);
        $this->assertSame('FACTURAS-001', $preview['path']);
        $this->assertSame(0, OrganizationNode::query()->count());
    }

    private function createScheme(?Workspace $workspace = null, ?int $positionCapacity = null): OrganizationScheme
    {
        return app(CreateScheme::class)->handle($workspace ?? Workspace::factory()->create(), 'Annual Archive', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
            ['name' => 'Position', 'key' => 'position', 'value_strategy' => NodeValueStrategy::Sequential, 'capacity' => $positionCapacity],
        ]);
    }

    private function fileDocument(Workspace $workspace, OrganizationNode $node): void
    {
        $creator = WorkspaceUser::factory()->for($workspace)->create();
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $creator->user, $type, 'Filed', null, null);

        app(MoveDocument::class)->handle($document, $node);
    }
}
