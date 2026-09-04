<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Actions\Documents\CreateDocument;
use App\Actions\Documents\MoveDocument;
use App\Actions\Organization\CreateOrganizationNode;
use App\Actions\Organization\CreateScheme;
use App\Actions\Organization\SuggestDocumentLocations;
use App\Enums\NodeValueStrategy;
use App\Models\DocumentType;
use App\Models\OrganizationNode;
use App\Models\OrganizationScheme;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class SuggestDocumentLocationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_recommended_pick_matches_find_available_location()
    {
        $workspace = Workspace::factory()->create();
        $creator = WorkspaceUser::factory()->for($workspace)->create();
        $type = DocumentType::factory()->for($workspace)->create(['key' => 'invoice']);
        $document = app(CreateDocument::class)->handle($workspace, $creator->user, $type, 'Original', null, null);

        $scheme = app(CreateScheme::class)->handle($workspace, 'Scheme', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);

        $suggestions = app(SuggestDocumentLocations::class)->handle($document, $scheme);

        $this->assertCount(1, $suggestions);
        $this->assertTrue($suggestions[0]['recommended']);
        $this->assertSame(0, $suggestions[0]['documentsCount']);
        // Nothing has been filed here yet, so the recommendation is a location
        // that does not exist: offered as a path, with no node behind it.
        $this->assertNull($suggestions[0]['node']['id']);
        $this->assertSame('001', $suggestions[0]['node']['path']);
        $this->assertSame(0, OrganizationNode::query()->count());
    }

    public function test_the_recommendation_is_an_existing_node_when_one_has_room()
    {
        $workspace = Workspace::factory()->create();
        $creator = WorkspaceUser::factory()->for($workspace)->create();
        $type = DocumentType::factory()->for($workspace)->create(['key' => 'invoice']);

        $scheme = app(CreateScheme::class)->handle($workspace, 'Scheme', [
            ['name' => 'Box', 'key' => 'box', 'value_strategy' => NodeValueStrategy::Sequential, 'capacity' => 3],
        ]);
        $box = app(CreateOrganizationNode::class)->handle($scheme->levels->first(), null, '001');

        $existingDocument = app(CreateDocument::class)->handle($workspace, $creator->user, $type, 'Existing', null, null);
        app(MoveDocument::class)->handle($existingDocument, $box);

        $document = app(CreateDocument::class)->handle($workspace, $creator->user, $type, 'New', null, null);

        $suggestions = app(SuggestDocumentLocations::class)->handle($document, $scheme);

        $this->assertTrue($suggestions[0]['recommended']);
        $this->assertSame($box->id, $suggestions[0]['node']['id']);
        $this->assertSame(1, $suggestions[0]['documentsCount']);
        $this->assertSame(3, $suggestions[0]['capacity']);
    }

    public function test_alternatives_exclude_nodes_at_capacity()
    {
        $workspace = Workspace::factory()->create();
        $creator = WorkspaceUser::factory()->for($workspace)->create();
        $type = DocumentType::factory()->for($workspace)->create(['key' => 'invoice']);

        $scheme = app(CreateScheme::class)->handle($workspace, 'Scheme', [
            ['name' => 'Box', 'key' => 'box', 'value_strategy' => NodeValueStrategy::Sequential, 'capacity' => 1],
        ]);
        $level = $scheme->levels->first();
        $fullNode = app(CreateOrganizationNode::class)->handle($level, null, '001');

        $existingDocument = app(CreateDocument::class)->handle($workspace, $creator->user, $type, 'Existing', null, null);
        app(MoveDocument::class)->handle($existingDocument, $fullNode);

        $document = app(CreateDocument::class)->handle($workspace, $creator->user, $type, 'New', null, null);

        $suggestions = app(SuggestDocumentLocations::class)->handle($document, $scheme);

        $this->assertFalse(collect($suggestions)->contains(fn ($s) => $s['node']['id'] === $fullNode->id));
    }

    public function test_the_location_a_document_is_already_in_is_never_suggested()
    {
        $workspace = Workspace::factory()->create();
        $creator = WorkspaceUser::factory()->for($workspace)->create();
        $type = DocumentType::factory()->for($workspace)->create(['key' => 'invoice']);

        $scheme = app(CreateScheme::class)->handle($workspace, 'Scheme', [
            ['name' => 'Box', 'key' => 'box', 'value_strategy' => NodeValueStrategy::Sequential, 'capacity' => 5],
        ]);
        $level = $scheme->levels->first();
        $createNode = app(CreateOrganizationNode::class);
        $filedIn = $createNode->handle($level, null, '001');
        $createNode->handle($level, null, '002');

        $document = app(CreateDocument::class)->handle($workspace, $creator->user, $type, 'Filed', null, null);
        app(MoveDocument::class)->handle($document, $filedIn);

        $suggestions = app(SuggestDocumentLocations::class)->handle($document->refresh(), $scheme);

        // 001 has room, so it is where the rules resolve to — but the document
        // is in it, and offering to move it there is offering to do nothing.
        $this->assertFalse(collect($suggestions)->contains(fn ($s) => $s['node']['id'] === $filedIn->id));
        $this->assertNotEmpty($suggestions);
    }

    public function test_scheme_with_no_levels_throws()
    {
        $workspace = Workspace::factory()->create();
        $creator = WorkspaceUser::factory()->for($workspace)->create();
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $creator->user, $type, 'Original', null, null);
        $scheme = OrganizationScheme::factory()->for($workspace)->create();

        $this->expectException(LogicException::class);

        app(SuggestDocumentLocations::class)->handle($document, $scheme);
    }
}
