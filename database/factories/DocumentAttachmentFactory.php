<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentAttachment>
 */
class DocumentAttachmentFactory extends Factory
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
            'uploaded_by' => User::factory(),
            'disk' => 'local',
            'path' => 'documents/'.fake()->uuid().'/'.fake()->uuid().'.pdf',
            'filename' => fake()->word().'.pdf',
            'mime_type' => 'application/pdf',
            'size' => fake()->numberBetween(1000, 5_000_000),
            'checksum' => hash('sha256', fake()->uuid()),
        ];
    }
}
