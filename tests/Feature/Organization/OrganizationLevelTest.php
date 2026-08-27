<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Actions\Organization\CreateOrganizationNode;
use App\Enums\NodeValueStrategy;
use App\Models\OrganizationLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OrganizationLevelTest extends TestCase
{
    use RefreshDatabase;

    public function test_sequential_strategy_generates_zero_padded_incrementing_values()
    {
        $level = OrganizationLevel::factory()->create([
            'position' => 1,
            'value_strategy' => NodeValueStrategy::Sequential,
        ]);
        $action = app(CreateOrganizationNode::class);

        $first = $action->handle($level, null);
        $second = $action->handle($level, null);
        $third = $action->handle($level, null);

        $this->assertSame('001', $first->value);
        $this->assertSame('002', $second->value);
        $this->assertSame('003', $third->value);
    }

    public function test_alphabetical_strategy_generates_spreadsheet_style_values()
    {
        $level = OrganizationLevel::factory()->create([
            'position' => 1,
            'value_strategy' => NodeValueStrategy::Alphabetical,
        ]);
        $action = app(CreateOrganizationNode::class);

        for ($i = 0; $i < 26; $i++) {
            $action->handle($level, null);
        }
        $twentySeventh = $action->handle($level, null);

        $this->assertSame('AA', $twentySeventh->value);
    }

    public function test_manual_strategy_requires_an_explicit_value()
    {
        $level = OrganizationLevel::factory()->create([
            'position' => 1,
            'value_strategy' => NodeValueStrategy::Manual,
        ]);
        $action = app(CreateOrganizationNode::class);

        $this->expectException(ValidationException::class);

        $action->handle($level, null);
    }

    public function test_manual_strategy_succeeds_with_an_explicit_value()
    {
        $level = OrganizationLevel::factory()->create([
            'position' => 1,
            'value_strategy' => NodeValueStrategy::Manual,
        ]);
        $action = app(CreateOrganizationNode::class);

        $node = $action->handle($level, null, 'A');

        $this->assertSame('A', $node->value);
    }

    public function test_physical_locations_cannot_exceed_configured_capacity()
    {
        $level = OrganizationLevel::factory()->create([
            'position' => 1,
            'capacity' => 2,
            'value_strategy' => NodeValueStrategy::Sequential,
        ]);
        $action = app(CreateOrganizationNode::class);

        $action->handle($level, null);
        $action->handle($level, null);

        $this->expectException(ValidationException::class);

        $action->handle($level, null);
    }
}
