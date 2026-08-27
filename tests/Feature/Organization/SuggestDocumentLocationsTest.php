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
