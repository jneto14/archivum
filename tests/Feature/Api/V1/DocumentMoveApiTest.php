<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Actions\Organization\CreateOrganizationNode;
use App\Actions\Organization\CreateOrganizationRule;
use App\Actions\Organization\CreateScheme;
use App\Enums\NodeValueStrategy;
use App\Enums\WorkspaceRole;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentMoveApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_document_can_be_moved_to_a_named_node()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $type = DocumentType::factory()->for($workspace)->create();
        $document = Document::factory()->for($workspace)->for($type)->create();

        $scheme = app(CreateScheme::class)->handle($workspace, 'Archive', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);
        $node = app(CreateOrganizationNode::class)->handle($scheme->levels->first(), null, '001');

        $response = $this->withToken($admin->user->createToken('CLI')->plainTextToken)
            ->postJson("/api/v1/documents/{$document->id}/move", ['node_id' => $node->id]);

        $response->assertOk()->assertJsonPath('data.current_location.node_id', $node->id);
        $this->assertSame($node->id, $document->refresh()->currentLocation->organization_node_id);
    }

    /**
     * The form worth having over a plain update: a client filing a batch does
     * not know the archive's shelves, so it asks the scheme where this belongs.
     */
    public function test_a_destination_can_be_resolved_from_the_scheme_rules()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $type = DocumentType::factory()->for($workspace)->create(['key' => 'invoice']);
        $document = Document::factory()->for($workspace)->for($type)->create();

        // Three levels, not two: the rule points at Letter/A, and the document
        // lands on the level below it. With Letter as the leaf there is no
        // sequential level left to allocate into, and a Manual one cannot be
        // created without a value.
        $scheme = app(CreateScheme::class)->handle($workspace, 'Traditional Archive', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
            ['name' => 'Letter', 'key' => 'letter', 'value_strategy' => NodeValueStrategy::Manual],
            ['name' => 'Position', 'key' => 'position', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);
        $levels = $scheme->levels()->orderBy('position')->get();

        $createNode = app(CreateOrganizationNode::class);
        $coverNode = $createNode->handle($levels[0], null, '001');
        $createNode->handle($levels[1], $coverNode, 'A');

        app(CreateOrganizationRule::class)->handle($scheme, 'document_type', 'invoice', $levels[1], 'A');

        $response = $this->withToken($admin->user->createToken('CLI')->plainTextToken)
            ->postJson("/api/v1/documents/{$document->id}/move", ['scheme_id' => $scheme->id]);

        $response->assertOk();
        $this->assertStringContainsString('-A-', $document->refresh()->currentLocation->node->path());
    }

    public function test_a_node_from_another_workspace_is_not_found()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $type = DocumentType::factory()->for($workspace)->create();
        $document = Document::factory()->for($workspace)->for($type)->create();

        $stranger = Workspace::factory()->create();
        $strangerScheme = app(CreateScheme::class)->handle($stranger, 'Archive', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);
        $strangerNode = app(CreateOrganizationNode::class)->handle($strangerScheme->levels->first(), null, '001');

        $response = $this->withToken($admin->user->createToken('CLI')->plainTextToken)
            ->postJson("/api/v1/documents/{$document->id}/move", ['node_id' => $strangerNode->id]);

        $response->assertNotFound();
        $this->assertNull($document->refresh()->currentLocation);
    }
}
