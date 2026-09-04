<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Actions\Documents\CreateDocument;
use App\Actions\Documents\MoveDocument;
use App\Actions\Organization\CreateOrganizationNode;
use App\Actions\Organization\CreateOrganizationRule;
use App\Actions\Organization\CreateScheme;
use App\Enums\NodeValueStrategy;
use App\Enums\WorkspaceRole;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\OrganizationScheme;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MoveDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_moving_to_an_explicit_node_records_a_location()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        [$document, $scheme] = $this->createDocumentAndScheme($workspace, $admin);
        $node = app(CreateOrganizationNode::class)->handle($scheme->levels->first(), null, '001');

        $response = $this->actingAs($admin->user)->post(route('documents.move', $document), [
            'node_id' => $node->id,
        ]);

        $response->assertRedirect();
        $this->assertSame($node->id, $document->currentLocation->organization_node_id);
    }

    public function test_auto_resolving_a_location_via_scheme_and_criteria()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $type = DocumentType::factory()->for($workspace)->create(['key' => 'invoice']);

        $scheme = app(CreateScheme::class)->handle($workspace, 'Traditional Archive', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
            ['name' => 'Letter', 'key' => 'letter', 'value_strategy' => NodeValueStrategy::Manual],
            ['name' => 'Position', 'key' => 'position', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);
        $levels = $scheme->levels()->orderBy('position')->get();
        $cover = $levels[0];
        $letter = $levels[1];

        $createNode = app(CreateOrganizationNode::class);
        $coverNode = $createNode->handle($cover, null, '001');
        $createNode->handle($letter, $coverNode, 'A');

        app(CreateOrganizationRule::class)->handle($scheme, 'document_type', 'invoice', $letter, 'A');

        $document = app(CreateDocument::class)->handle($workspace, $admin->user, $type, 'Invoice', null, null);

        $response = $this->actingAs($admin->user)->post(route('documents.move', $document), [
            'scheme_id' => $scheme->id,
        ]);

        $response->assertRedirect()->assertSessionHasNoErrors();
        $this->assertStringContainsString('-A-', $document->currentLocation->node->path());
    }

    public function test_filing_by_scheme_opens_the_suggested_location_when_it_does_not_exist_yet()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        [$document, $scheme] = $this->createDocumentAndScheme($workspace, $admin);

        // What the show page posts when the user picks a recommendation the
        // suggestion deliberately did not create.
        $response = $this->actingAs($admin->user)->post(route('documents.move', $document), [
            'scheme_id' => $scheme->id,
        ]);

        $response->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('001', $document->currentLocation->node->path());
    }

    public function test_moving_twice_builds_history_with_current_location_as_the_latest()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        [$document, $scheme] = $this->createDocumentAndScheme($workspace, $admin);
        $level = $scheme->levels->first();
        $nodeOne = app(CreateOrganizationNode::class)->handle($level, null, '001');
        $nodeTwo = app(CreateOrganizationNode::class)->handle($level, null, '002');

        app(MoveDocument::class)->handle($document, $nodeOne);
        app(MoveDocument::class)->handle($document, $nodeTwo);

        $document->refresh();

        $this->assertCount(2, $document->locations);
        $this->assertSame($nodeTwo->id, $document->locations->first()->organization_node_id);
        $this->assertSame($nodeTwo->id, $document->currentLocation->organization_node_id);
    }

    public function test_cannot_move_to_a_node_from_a_different_workspace()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        [$document] = $this->createDocumentAndScheme($workspace, $admin);

        $foreignScheme = app(CreateScheme::class)->handle(Workspace::factory()->create(), 'Foreign Scheme', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);
        $foreignNode = app(CreateOrganizationNode::class)->handle($foreignScheme->levels->first(), null, '001');

        $response = $this->actingAs($admin->user)->post(route('documents.move', $document), [
            'node_id' => $foreignNode->id,
        ]);

        $response->assertNotFound();

        $this->expectException(ValidationException::class);

        app(MoveDocument::class)->handle($document, $foreignNode);
    }

    public function test_node_id_and_scheme_id_are_mutually_exclusive()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        [$document, $scheme] = $this->createDocumentAndScheme($workspace, $admin);
        $node = app(CreateOrganizationNode::class)->handle($scheme->levels->first(), null, '001');

        $this->actingAs($admin->user)->post(route('documents.move', $document), [
            'node_id' => $node->id,
            'scheme_id' => $scheme->id,
        ])->assertSessionHasErrors();

        $this->actingAs($admin->user)->post(route('documents.move', $document), [])
            ->assertSessionHasErrors();
    }

    /**
     * @return array{0: Document, 1: OrganizationScheme}
     */
    private function createDocumentAndScheme(Workspace $workspace, WorkspaceUser $admin): array
    {
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $admin->user, $type, 'Original', null, null);

        $scheme = app(CreateScheme::class)->handle($workspace, 'Scheme', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);

        return [$document, $scheme];
    }
}
