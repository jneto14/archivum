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
                'capacity' => __('organization.capacity_reached'),
            ]);
        }

        if ($value === null) {
            if ($level->value_strategy === NodeValueStrategy::Manual) {
                throw ValidationException::withMessages([
                    'value' => __('organization.value_required'),
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
                    'parent_id' => __('organization.root_node_cannot_have_parent'),
                ]);
            }

            return;
        }

        if ($parent === null || $parent->level->scheme_id !== $level->scheme_id || $parent->level->position !== $level->position - 1) {
            throw ValidationException::withMessages([
                'parent_id' => __('organization.invalid_parent_level'),
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
                'value' => __('organization.duplicate_node_value'),
            ]);
        }
    }
}
