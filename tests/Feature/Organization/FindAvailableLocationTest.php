<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Actions\Organization\CreateOrganizationRule;
use App\Actions\Organization\CreateScheme;
use App\Actions\Organization\FindAvailableLocation;
use App\Enums\NodeValueStrategy;
use App\Models\OrganizationScheme;
use App\Models\Workspace;
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

    public function test_a_second_call_reuses_a_non_full_branch()
    {
        $scheme = $this->createScheme(positionCapacity: 5);

        $first = app(FindAvailableLocation::class)->handle($scheme);
        $second = app(FindAvailableLocation::class)->handle($scheme);

        $this->assertSame('001-001', $first->path());
        $this->assertSame('001-002', $second->path());
    }

    public function test_a_full_branch_triggers_a_new_branch()
    {
        $scheme = $this->createScheme(positionCapacity: 1);

        $first = app(FindAvailableLocation::class)->handle($scheme);
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

    private function createScheme(?int $positionCapacity = null): OrganizationScheme
    {
        return app(CreateScheme::class)->handle(Workspace::factory()->create(), 'Annual Archive', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
            ['name' => 'Position', 'key' => 'position', 'value_strategy' => NodeValueStrategy::Sequential, 'capacity' => $positionCapacity],
        ]);
    }
}
