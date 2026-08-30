<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
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
            'user_id' => User::factory(),
            'type' => TaskType::DocumentExport,
            'status' => TaskStatus::Queued,
            'payload' => null,
            'result' => null,
            'started_at' => null,
            'finished_at' => null,
        ];
    }

    /**
     * Indicate that the task completed successfully.
     */
    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => TaskStatus::Completed,
            'result' => ['disk' => 'local', 'path' => 'exports/example.csv'],
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);
    }

    /**
     * Indicate that the task failed.
     */
    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => TaskStatus::Failed,
            'result' => ['error' => 'Something went wrong.'],
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);
    }
}
