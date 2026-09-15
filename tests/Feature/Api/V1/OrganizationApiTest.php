<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Actions\Documents\MoveDocument;
use App\Actions\Organization\CreateOrganizationNode;
use App\Actions\Organization\CreateOrganizationRule;
use App\Actions\Organization\CreateScheme;
use App\Enums\NodeValueStrategy;
use App\Enums\WorkspaceRole;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\OrganizationScheme;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationApiTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($this->workspace)->create(['role' => WorkspaceRole::Admin]);
        $this->admin = $member->user;
        $this->token = $this->admin->createToken('CLI')->plainTextToken;
    }

    private function scheme(): OrganizationScheme
    {
        return app(CreateScheme::class)->handle($this->workspace, 'Traditional Archive', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
            ['name' => 'Position', 'key' => 'position', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);
    }

    public function test_a_scheme_can_be_created_with_its_levels()
    {
        $response = $this->withToken($this->token)->postJson(
            "/api/v1/workspaces/{$this->workspace->id}/organization/schemes",
            [
                'name' => 'Traditional Archive',
                'levels' => [
                    ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => 'sequential'],
                    ['name' => 'Position', 'key' => 'position', 'value_strategy' => 'sequential'],
                ],
            ],
        );

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Traditional Archive')
            ->assertJsonCount(2, 'data.levels')
            ->assertJsonPath('data.levels.0.key', 'cover')
            // The bottom tier, where documents come to rest.
            ->assertJsonPath('data.levels.1.is_leaf', true);
    }

    public function test_reading_a_scheme_gives_the_levels_and_rules_a_client_needs_to_file_with()
    {
        $scheme = $this->scheme();

        $this->withToken($this->token)
            ->getJson("/api/v1/organization/schemes/{$scheme->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $scheme->id)
            ->assertJsonCount(2, 'data.levels')
            // Ordered by position, which is what tells a client which tier
            // is the cover and which is the shelf.
            ->assertJsonPath('data.levels.0.key', 'cover')
            ->assertJsonPath('data.levels.1.key', 'position');
    }

    public function test_schemes_are_listed_for_a_workspace()
    {
        $this->scheme();

        $this->withToken($this->token)
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/organization/schemes")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /**
     * A client opening one cover does not want every shelf in the building,
     * which is why this walks a tier at a time rather than returning the tree.
     */
    public function test_nodes_are_browsed_one_tier_at_a_time()
    {
        $scheme = $this->scheme();
        $levels = $scheme->levels()->orderBy('position')->get();
        $createNode = app(CreateOrganizationNode::class);
        $cover = $createNode->handle($levels[0], null, '001');
        $createNode->handle($levels[1], $cover, 'A');

        $this->withToken($this->token)
            ->getJson("/api/v1/organization/schemes/{$scheme->id}/nodes")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $cover->id)
            ->assertJsonPath('data.0.children_count', 1);

        $this->withToken($this->token)
            ->getJson("/api/v1/organization/schemes/{$scheme->id}/nodes?parent_id={$cover->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.value', 'A');
    }

    public function test_a_node_can_be_created_and_deleted()
    {
        $scheme = $this->scheme();
        $level = $scheme->levels()->orderBy('position')->first();

        $created = $this->withToken($this->token)->postJson(
            "/api/v1/organization/schemes/{$scheme->id}/nodes",
            ['level_id' => $level->id, 'value' => '001'],
        );

        $created->assertCreated()->assertJsonPath('data.value', '001');
        $id = $created->json('data.id');

        $this->withToken($this->token)
            ->deleteJson("/api/v1/organization/schemes/{$scheme->id}/nodes/{$id}")
            ->assertNoContent();
    }

    public function test_a_node_reports_the_documents_filed_at_it()
    {
        $scheme = $this->scheme();
        $levels = $scheme->levels()->orderBy('position')->get();
        $createNode = app(CreateOrganizationNode::class);
        $cover = $createNode->handle($levels[0], null, '001');
        $position = $createNode->handle($levels[1], $cover, 'A');

        $type = DocumentType::factory()->for($this->workspace)->create();
        $document = Document::factory()->for($this->workspace)->for($type)->create();
        app(MoveDocument::class)->handle($document, $position);

        $this->withToken($this->token)
            ->getJson("/api/v1/organization/nodes/{$position->id}/documents")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $document->id);
    }

    public function test_a_rule_can_be_created_updated_and_deleted()
    {
        $scheme = $this->scheme();
        $level = $scheme->levels()->orderBy('position')->first();

        $created = $this->withToken($this->token)->postJson(
            "/api/v1/organization/schemes/{$scheme->id}/rules",
            [
                'matcher_key' => 'document_type',
                'matcher_value' => 'invoice',
                'target_level_id' => $level->id,
                'preferred_value' => '001',
            ],
        );

        $created->assertCreated()
            ->assertJsonPath('data.matcher_value', 'invoice')
            ->assertJsonPath('data.target_level.id', $level->id);

        $id = $created->json('data.id');

        $this->withToken($this->token)->patchJson(
            "/api/v1/organization/schemes/{$scheme->id}/rules/{$id}",
            [
                'matcher_key' => 'document_type',
                'matcher_value' => 'receipt',
                'target_level_id' => $level->id,
                'preferred_value' => '001',
            ],
        )->assertOk()->assertJsonPath('data.matcher_value', 'receipt');

        $this->withToken($this->token)
            ->deleteJson("/api/v1/organization/schemes/{$scheme->id}/rules/{$id}")
            ->assertNoContent();
    }

    /**
     * The URL the code points at, not a rendered image: a client making labels
     * has its own idea of size, margins and error correction.
     */
    public function test_labels_report_the_url_their_code_encodes()
    {
        $scheme = app(CreateScheme::class)->handle($this->workspace, 'Archive', [
            [
                'name' => 'Cover',
                'key' => 'cover',
                'value_strategy' => NodeValueStrategy::Sequential,
                'has_printable_label' => true,
            ],
        ]);
        $node = app(CreateOrganizationNode::class)->handle($scheme->levels->first(), null, '001');

        $this->withToken($this->token)
            ->getJson("/api/v1/organization/schemes/{$scheme->id}/labels")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.node_id', $node->id)
            ->assertJsonPath('data.0.url', fn (string $url): bool => str_contains($url, $node->id));
    }

    public function test_a_rule_from_another_scheme_is_not_found()
    {
        $scheme = $this->scheme();

        // In another workspace, because a workspace holds one scheme. The rule
        // is never reachable here; what is being asserted is that naming it
        // under a scheme it does not belong to answers 404 rather than acting.
        $other = app(CreateScheme::class)->handle(Workspace::factory()->create(), 'Other', [
            ['name' => 'Box', 'key' => 'box', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);

        $rule = app(CreateOrganizationRule::class)->handle(
            $other,
            'document_type',
            'invoice',
            $other->levels->first(),
            '001',
        );

        $this->withToken($this->token)
            ->deleteJson("/api/v1/organization/schemes/{$scheme->id}/rules/{$rule->id}")
            ->assertNotFound();
    }

    public function test_another_workspace_s_scheme_is_out_of_reach()
    {
        $stranger = Workspace::factory()->create();
        $theirs = app(CreateScheme::class)->handle($stranger, 'Theirs', [
            ['name' => 'Box', 'key' => 'box', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);

        $this->withToken($this->token)
            ->getJson("/api/v1/organization/schemes/{$theirs->id}")
            ->assertForbidden();
    }
}
