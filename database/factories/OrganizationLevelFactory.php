<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NodeValueStrategy;
use App\Models\OrganizationLevel;
use App\Models\OrganizationScheme;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationLevel>
 */
class OrganizationLevelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scheme_id' => OrganizationScheme::factory(),
            'name' => fake()->unique()->word(),
            'key' => fake()->unique()->slug(2),
            'position' => 1,
            'capacity' => null,
            'value_strategy' => NodeValueStrategy::Sequential,
            'display_settings' => null,
            'metadata' => null,
        ];
    }
}
