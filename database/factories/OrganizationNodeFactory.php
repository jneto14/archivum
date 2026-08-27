<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\OrganizationLevel;
use App\Models\OrganizationNode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationNode>
 */
class OrganizationNodeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'level_id' => OrganizationLevel::factory(),
            'parent_id' => null,
            'value' => fake()->unique()->numerify('###'),
        ];
    }
}
