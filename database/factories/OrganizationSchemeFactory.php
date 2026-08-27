<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\OrganizationScheme;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationScheme>
 */
class OrganizationSchemeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->words(2, true),
        ];
    }
}
