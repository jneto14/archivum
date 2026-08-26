<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentLocation;
use App\Models\OrganizationNode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentLocation>
 */
class DocumentLocationFactory extends Factory
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
            'organization_node_id' => OrganizationNode::factory(),
        ];
    }
}
