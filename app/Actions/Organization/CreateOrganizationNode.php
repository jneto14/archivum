<?php

namespace App\Actions\Organization;

use App\Enums\NodeValueStrategy;
use App\Models\OrganizationLevel;
use App\Models\OrganizationNode;
use Illuminate\Validation\ValidationException;

class CreateOrganizationNode
{
    /**
     * Create a new OrganizationNode under the given parent (or as a root node
     * when the level is the first in its scheme), auto-generating the value
     * when the level's strategy allows it.
     */
    public function handle(OrganizationLevel $level, ?OrganizationNode $parent, ?string $value = null): OrganizationNode
    {
        $this->assertParentConsistency($level, $parent);

        if ($level->capacityReached($parent)) {
            throw ValidationException::withMessages([
                'capacity' => 'This level has reached its configured capacity.',
            ]);
        }

        if ($value === null) {
            if ($level->value_strategy === NodeValueStrategy::Manual) {
                throw ValidationException::withMessages([
                    'value' => 'A value is required for levels using the Manual strategy.',
                ]);
            }

            $value = $level->nextValueForParent($parent);
        }

        $this->assertValueIsUnique($level, $parent, $value);

        return OrganizationNode::query()->create([
            'level_id' => $level->id,
            'parent_id' => $parent?->id,
            'value' => $value,
        ]);
    }

    private function assertParentConsistency(OrganizationLevel $level, ?OrganizationNode $parent): void
    {
        if ($level->position === 1) {
            if ($parent !== null) {
                throw ValidationException::withMessages([
                    'parent_id' => 'A node at the first level cannot have a parent.',
                ]);
            }

            return;
        }

        if ($parent === null || $parent->level->scheme_id !== $level->scheme_id || $parent->level->position !== $level->position - 1) {
            throw ValidationException::withMessages([
                'parent_id' => 'The parent node must belong to the immediately preceding level of the same scheme.',
            ]);
        }
    }

    private function assertValueIsUnique(OrganizationLevel $level, ?OrganizationNode $parent, string $value): void
    {
        $exists = OrganizationNode::query()
            ->where('level_id', $level->id)
            ->when($parent === null, fn ($query) => $query->whereNull('parent_id'))
            ->when($parent !== null, fn ($query) => $query->where('parent_id', $parent->id))
            ->where('value', $value)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'value' => 'A node with this value already exists at this level under the same parent.',
            ]);
        }
    }
}
