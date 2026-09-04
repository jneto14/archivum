<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Actions\Documents\CreateDocument;
use App\Actions\Documents\MoveDocument;
use App\Actions\Organization\CreateOrganizationNode;
use App\Actions\Organization\CreateScheme;
use App\Actions\Organization\MigrateNodeDocuments;
use App\Enums\NodeValueStrategy;
use App\Models\DocumentType;
use App\Models\OrganizationScheme;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

class MigrateNodeDocumentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_documents_at_the_source_node_are_relocated_to_the_target_node()
    {
        $workspace = Workspace::factory()->create();
        $type = DocumentType::factory()->for($workspace)->create(['key' => 'invoice']);
        $scheme = $this->createScheme($workspace);
        $level = $scheme->levels->first();

        $sourceNode = app(CreateOrganizationNode::class)->handle($level, null, '001');
        $targetNode = app(CreateOrganizationNode::class)->handle($level, null, '002');

        $document = app(CreateDocument::class)->handle($workspace, User::factory()->create(), $type, 'Invoice', null, null);
        app(MoveDocument::class)->handle($document, $sourceNode);

        app(MigrateNodeDocuments::class)->handle($sourceNode, $targetNode);

        $document->refresh();
        $this->assertSame($targetNode->id, $document->currentLocation->organization_node_id);
        $this->assertCount(2, $document->locations);
    }

    public function test_documents_at_other_nodes_are_left_untouched()
    {
        $workspace = Workspace::factory()->create();
        $type = DocumentType::factory()->for($workspace)->create(['key' => 'invoice']);
        $creator = User::factory()->create();
        $scheme = $this->createScheme($workspace);
        $level = $scheme->levels->first();

        $sourceNode = app(CreateOrganizationNode::class)->handle($level, null, '001');
        $targetNode = app(CreateOrganizationNode::class)->handle($level, null, '002');
        $otherNode = app(CreateOrganizationNode::class)->handle($level, null, '003');

        $documentAtOtherNode = app(CreateDocument::class)->handle($workspace, $creator, $type, 'Untouched', null, null);
        app(MoveDocument::class)->handle($documentAtOtherNode, $otherNode);

        $documentWithNoLocation = app(CreateDocument::class)->handle($workspace, $creator, $type, 'No Location', null, null);

        app(MigrateNodeDocuments::class)->handle($sourceNode, $targetNode);

        $documentAtOtherNode->refresh();
        $this->assertSame($otherNode->id, $documentAtOtherNode->currentLocation->organization_node_id);
        $this->assertNull($documentWithNoLocation->fresh()->currentLocation);
    }

    public function test_a_migration_the_target_cannot_hold_is_refused_whole()
    {
        $workspace = Workspace::factory()->create();
        $type = DocumentType::factory()->for($workspace)->create(['key' => 'invoice']);
        $creator = User::factory()->create();
        $scheme = $this->createScheme($workspace, capacity: 2);
        $level = $scheme->levels->first();

        $sourceNode = app(CreateOrganizationNode::class)->handle($level, null, '001');
        $targetNode = app(CreateOrganizationNode::class)->handle($level, null, '002');

        $moving = [];

        foreach (range(1, 2) as $index) {
            $moving[] = $document = app(CreateDocument::class)->handle($workspace, $creator, $type, "Moving {$index}", null, null);
            app(MoveDocument::class)->handle($document, $sourceNode);
        }

        $filed = app(CreateDocument::class)->handle($workspace, $creator, $type, 'Already there', null, null);
        app(MoveDocument::class)->handle($filed, $targetNode);

        // The target holds one of its two, and two are on their way: filing
        // both would leave it holding three.
        try {
            app(MigrateNodeDocuments::class)->handle($sourceNode, $targetNode);
            $this->fail('Expected the migration to be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('target_node_id', $exception->errors());
        }

        // Refused whole: not one of them moved, rather than the first fitting
        // and the second being turned away halfway through.
        foreach ($moving as $document) {
            $this->assertSame($sourceNode->id, $document->refresh()->currentLocation->organization_node_id);
        }
    }

    public function test_a_migration_that_exactly_fills_the_target_is_allowed()
    {
        $workspace = Workspace::factory()->create();
        $type = DocumentType::factory()->for($workspace)->create(['key' => 'invoice']);
        $creator = User::factory()->create();
        $scheme = $this->createScheme($workspace, capacity: 2);
        $level = $scheme->levels->first();

        $sourceNode = app(CreateOrganizationNode::class)->handle($level, null, '001');
        $targetNode = app(CreateOrganizationNode::class)->handle($level, null, '002');

        $document = app(CreateDocument::class)->handle($workspace, $creator, $type, 'Moving', null, null);
        app(MoveDocument::class)->handle($document, $sourceNode);

        $filed = app(CreateDocument::class)->handle($workspace, $creator, $type, 'Already there', null, null);
        app(MoveDocument::class)->handle($filed, $targetNode);

        app(MigrateNodeDocuments::class)->handle($sourceNode, $targetNode);

        $this->assertSame($targetNode->id, $document->refresh()->currentLocation->organization_node_id);
    }

    public function test_migrating_to_the_same_node_throws()
    {
        $workspace = Workspace::factory()->create();
        $scheme = $this->createScheme($workspace);
        $node = app(CreateOrganizationNode::class)->handle($scheme->levels->first(), null, '001');

        $this->expectException(InvalidArgumentException::class);

        app(MigrateNodeDocuments::class)->handle($node, $node);
    }

    public function test_migrating_to_a_node_from_a_different_workspace_throws()
    {
        $sourceScheme = $this->createScheme(Workspace::factory()->create());
        $sourceNode = app(CreateOrganizationNode::class)->handle($sourceScheme->levels->first(), null, '001');

        $targetScheme = $this->createScheme(Workspace::factory()->create());
        $targetNode = app(CreateOrganizationNode::class)->handle($targetScheme->levels->first(), null, '001');

        $this->expectException(InvalidArgumentException::class);

        app(MigrateNodeDocuments::class)->handle($sourceNode, $targetNode);
    }

    private function createScheme(Workspace $workspace, ?int $capacity = null): OrganizationScheme
    {
        return app(CreateScheme::class)->handle($workspace, 'Traditional Archive', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential, 'capacity' => $capacity],
        ]);
    }
}
