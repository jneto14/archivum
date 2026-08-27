<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\OrganizationLevel;
use App\Models\OrganizationRule;
use App\Models\OrganizationScheme;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationRule>
 */
class OrganizationRuleFactory extends Factory
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
            'matcher_key' => 'document_type',
            'matcher_value' => fake()->unique()->word(),
            'target_level_id' => OrganizationLevel::factory(),
            'preferred_value' => fake()->randomLetter(),
        ];
    }
}
