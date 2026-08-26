<?php

namespace Tests\Feature\Organization;

use App\Actions\Organization\ApplyOrganizationRules;
use App\Actions\Organization\CreateOrganizationNode;
use App\Actions\Organization\CreateOrganizationRule;
use App\Actions\Organization\CreateScheme;
use App\Actions\Organization\FindAvailableLocation;
use App\Enums\NodeValueStrategy;
use App\Models\OrganizationScheme;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplyOrganizationRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_criteria_returns_null()
    {
        $scheme = OrganizationScheme::factory()->create();

        $result = app(ApplyOrganizationRules::class)->handle($scheme, []);

        $this->assertNull($result);
    }

    public function test_no_matching_rule_returns_null()
    {
        $scheme = $this->createScheme();
        $letter = $scheme->levels()->where('key', 'letter')->firstOrFail();

        app(CreateOrganizationRule::class)->handle($scheme, 'document_type', 'invoice', $letter, 'A');

        $result = app(ApplyOrganizationRules::class)->handle($scheme, ['document_type' => 'receipt']);

        $this->assertNull($result);
    }

    public function test_matching_rule_returns_the_target_level_and_preferred_value()
    {
        $scheme = $this->createScheme();
        $letter = $scheme->levels()->where('key', 'letter')->firstOrFail();

        app(CreateOrganizationRule::class)->handle($scheme, 'document_type', 'invoice', $letter, 'A');

        $result = app(ApplyOrganizationRules::class)->handle($scheme, ['document_type' => 'invoice']);

        $this->assertSame($letter->id, $result['level']->id);
        $this->assertSame('A', $result['preferred_value']);
    }

    public function test_find_available_location_honours_a_matching_rule_end_to_end()
    {
        $scheme = $this->createScheme();
        $levels = $scheme->levels()->orderBy('position')->get();
        $cover = $levels[0];
        $letter = $levels[1];

        $createNode = app(CreateOrganizationNode::class);
        $coverNode = $createNode->handle($cover, null, '001');
        $createNode->handle($letter, $coverNode, 'A');
        $createNode->handle($letter, $coverNode, 'B');
        $createNode->handle($letter, $coverNode, 'C');

        app(CreateOrganizationRule::class)->handle($scheme, 'document_type', 'invoice', $letter, 'A');

        $node = app(FindAvailableLocation::class)->handle($scheme, ['document_type' => 'invoice']);

        $this->assertStringContainsString('-A-', $node->path());
    }

    private function createScheme(): OrganizationScheme
    {
        return app(CreateScheme::class)->handle(Workspace::factory()->create(), 'Traditional Archive', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
            ['name' => 'Letter', 'key' => 'letter', 'value_strategy' => NodeValueStrategy::Manual],
            ['name' => 'Position', 'key' => 'position', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);
    }
}
