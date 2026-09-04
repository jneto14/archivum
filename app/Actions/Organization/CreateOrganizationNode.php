<?php

declare(strict_types=1);

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
     *
     * @param OrganizationLevel $level The level the new node belongs to.
     * @param OrganizationNode|null $parent The parent node, or null when $level is the scheme's root level.
     * @param string|null $value The node's value; if null, it is auto-generated from $level's value strategy (unless the strategy is Manual).
     *
     * @return OrganizationNode The newly created node.
     *
     * @throws ValidationException If $parent is inconsistent with $level's position in the scheme, $level has reached capacity under $parent, $value is null under a Manual value strategy, or the resulting value is not unique among siblings.
     */
    public function handle(OrganizationLevel $level, ?OrganizationNode $parent, ?string $value = null): OrganizationNode
    {
        return OrganizationNode::query()->create([
            'level_id' => $level->id,
            'parent_id' => $parent?->id,
            'value' => $this->resolveValue($level, $parent, $value),
        ]);
    }

    /**
     * Work out the value a node created by handle() would take, running every check
     * handle() runs, without writing anything. Callers that only want to know where a
     * document *would* be filed use this to avoid persisting a node they may not need
     * (see FindAvailableLocation::preview()).
     *
     * @param OrganizationLevel $level The level the node would belong to.
     * @param OrganizationNode|null $parent The parent node, or null when $level is the scheme's root level.
     * @param string|null $value The requested value; if null, it is derived from $level's value strategy (unless the strategy is Manual).
     *
     * @return string The value the new node would be created with.
     *
     * @throws ValidationException If $parent is inconsistent with $level's position in the scheme, $level has reached capacity under $parent, $value is null under a Manual value strategy, or the resulting value is not unique among siblings.
     */
    public function resolveValue(OrganizationLevel $level, ?OrganizationNode $parent, ?string $value = null): string
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

        return $value;
    }

    /**
     * @param OrganizationLevel $level The level the new node would belong to.
     * @param OrganizationNode|null $parent The candidate parent node, or null for a root-level node.
     *
     * @return void No return value when consistent.
     *
     * @throws ValidationException If $level is the root level but $parent is set, or $parent does not belong to the level immediately above $level.
     */
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

    /**
     * @param OrganizationLevel $level The level the new node would belong to.
     * @param OrganizationNode|null $parent The parent node the sibling check is scoped to, or null for root-level nodes.
     * @param string $value The candidate node value.
     *
     * @return void No return value when unique.
     *
     * @throws ValidationException If a sibling node under the same parent and level already has $value.
     */
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
