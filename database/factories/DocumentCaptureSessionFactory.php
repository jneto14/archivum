<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CaptureSessionStatus;
use App\Models\Document;
use App\Models\DocumentCaptureSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentCaptureSession>
 */
class DocumentCaptureSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'created_by' => User::factory(),
            'status' => CaptureSessionStatus::Active,
            'photos_count' => 0,
            'expires_at' => now()->addMinutes(10),
        ];
    }

    /**
     * @return static
     */
    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subMinute()]);
    }

    /**
     * @return static
     */
    public function cancelled(): static
    {
        return $this->state(fn (): array => ['status' => CaptureSessionStatus::Cancelled]);
    }

    /**
     * @return static
     */
    public function completed(): static
    {
        return $this->state(fn (): array => ['status' => CaptureSessionStatus::Completed]);
    }
}
