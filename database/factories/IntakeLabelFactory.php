<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\IntakeLabelStatus;
use App\Models\IntakeLabel;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntakeLabel>
 */
class IntakeLabelFactory extends Factory
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
            'kind' => 'tax_id',
            'label' => fake()->unique()->word(),
            'status' => IntakeLabelStatus::Pending,
            'support' => 3,
        ];
    }

    /**
     * @return static A label the workspace said yes to, which the reader uses.
     */
    public function accepted(): static
    {
        return $this->state(['status' => IntakeLabelStatus::Accepted]);
    }

    /**
     * @return static A label the workspace turned down, which mining must not offer again.
     */
    public function rejected(): static
    {
        return $this->state(['status' => IntakeLabelStatus::Rejected]);
    }
}
