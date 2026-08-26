<?php

namespace Tests\Feature\Organization;

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

    private function createScheme(?int $positionCapacity = null): OrganizationScheme
    {
        return app(CreateScheme::class)->handle(Workspace::factory()->create(), 'Annual Archive', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
            ['name' => 'Position', 'key' => 'position', 'value_strategy' => NodeValueStrategy::Sequential, 'capacity' => $positionCapacity],
        ]);
    }
}
