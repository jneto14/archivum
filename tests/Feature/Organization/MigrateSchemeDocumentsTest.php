<?php

namespace Tests\Feature\Organization;

use App\Actions\Documents\CreateDocument;
use App\Actions\Documents\MoveDocument;
use App\Actions\Organization\CreateOrganizationNode;
use App\Actions\Organization\CreateOrganizationRule;
use App\Actions\Organization\CreateScheme;
use App\Actions\Organization\MigrateSchemeDocuments;
use App\Enums\NodeValueStrategy;
use App\Models\DocumentType;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class MigrateSchemeDocumentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_documents_under_the_source_scheme_are_relocated_to_the_target_scheme()
    {
        $workspace = Workspace::factory()->create();
        $type = DocumentType::factory()->for($workspace)->create(['key' => 'invoice']);

        $source = app(CreateScheme::class)->handle($workspace, 'Source', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);
        $sourceNode = app(CreateOrganizationNode::class)->handle($source->levels->first(), null, '001');

        $target = app(CreateScheme::class)->handle($workspace, 'Target', [
            ['name' => 'Year', 'key' => 'year', 'value_strategy' => NodeValueStrategy::Manual],
        ]);
        $targetLevel = $target->levels->first();
        app(CreateOrganizationRule::class)->handle($target, 'document_type', 'invoice', $targetLevel, '2026');

        $document = app(CreateDocument::class)->handle($workspace, User::factory()->create(), $type, 'Invoice', null, null);
        app(MoveDocument::class)->handle($document, $sourceNode);

        app(MigrateSchemeDocuments::class)->handle($source, $target);

        $document->refresh();
        $this->assertStringContainsString('2026', $document->currentLocation->node->path());
        $this->assertCount(2, $document->locations);
    }

    public function test_documents_outside_the_source_scheme_are_left_untouched()
    {
        $workspace = Workspace::factory()->create();
        $type = DocumentType::factory()->for($workspace)->create(['key' => 'invoice']);
        $creator = User::factory()->create();

        $source = app(CreateScheme::class)->handle($workspace, 'Source', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);

        $other = app(CreateScheme::class)->handle($workspace, 'Other', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);
        $otherNode = app(CreateOrganizationNode::class)->handle($other->levels->first(), null, '001');

        $target = app(CreateScheme::class)->handle($workspace, 'Target', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);

        $documentInOtherScheme = app(CreateDocument::class)->handle($workspace, $creator, $type, 'Untouched', null, null);
        app(MoveDocument::class)->handle($documentInOtherScheme, $otherNode);

        $documentWithNoLocation = app(CreateDocument::class)->handle($workspace, $creator, $type, 'No Location', null, null);

        app(MigrateSchemeDocuments::class)->handle($source, $target);

        $documentInOtherScheme->refresh();
        $this->assertSame($otherNode->id, $documentInOtherScheme->currentLocation->organization_node_id);
        $this->assertNull($documentWithNoLocation->fresh()->currentLocation);
    }

    public function test_migrating_to_the_same_scheme_throws()
    {
        $workspace = Workspace::factory()->create();
        $scheme = app(CreateScheme::class)->handle($workspace, 'Scheme', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);

        $this->expectException(InvalidArgumentException::class);

        app(MigrateSchemeDocuments::class)->handle($scheme, $scheme);
    }

    public function test_migrating_to_a_scheme_from_a_different_workspace_throws()
    {
        $source = app(CreateScheme::class)->handle(Workspace::factory()->create(), 'Source', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);
        $target = app(CreateScheme::class)->handle(Workspace::factory()->create(), 'Target', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);

        $this->expectException(InvalidArgumentException::class);

        app(MigrateSchemeDocuments::class)->handle($source, $target);
    }
}
