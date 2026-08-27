<?php

namespace App\Actions\Organization;

use App\Models\OrganizationLevel;
use App\Models\OrganizationNode;
use App\Models\OrganizationScheme;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use LogicException;

class FindAvailableLocation
{
    public function __construct(
        private readonly ApplyOrganizationRules $applyOrganizationRules,
        private readonly CreateOrganizationNode $createOrganizationNode,
    ) {}

    /**
     * Find (or create) the first available leaf OrganizationNode for the
     * given criteria, following ApplyOrganizationRules → OrganizationNode.
     *
     * @param  OrganizationScheme  $scheme  The scheme to resolve/create a location within.
     * @param  array<string, string>  $criteria  Matcher key/value pairs used to look up a preferred placement via ApplyOrganizationRules.
     * @return OrganizationNode The resolved (or newly created) leaf node.
     *
     * @throws LogicException If $scheme has no levels defined.
     * @throws ValidationException If creating a node along the resolved path fails validation (see CreateOrganizationNode::handle()).
     */
    public function handle(OrganizationScheme $scheme, array $criteria = []): OrganizationNode
    {
        $levels = $scheme->levels;
        $firstLevel = $levels->first();

        if ($firstLevel === null) {
            throw new LogicException('Cannot find an available location for a scheme with no levels.');
        }

        $rule = $this->applyOrganizationRules->handle($scheme, $criteria);

        $node = $this->resolveNode($firstLevel, null, $this->preferredValueFor($rule, $firstLevel));

        foreach ($levels->skip(1) as $level) {
            $node = $this->resolveNode($level, $node, $this->preferredValueFor($rule, $level));
        }

        return $node;
    }

    /**
     * @param  array{level: OrganizationLevel, preferred_value: string}|null  $rule  The rule resolved by ApplyOrganizationRules, if any.
     * @return string|null The preferred value to use at $level, or null if $rule doesn't target $level.
     */
    private function preferredValueFor(?array $rule, OrganizationLevel $level): ?string
    {
        if ($rule === null || $rule['level']->id !== $level->id) {
            return null;
        }

        return $rule['preferred_value'];
    }

    /**
     * Resolve (or create) the node at $level under $parent, preferring $preferredValue when possible and
     * falling back to the first sibling with room, or creating a new node.
     *
     * @param  OrganizationLevel  $level  The level to resolve a node at.
     * @param  OrganizationNode|null  $parent  The parent node already resolved for the level above, or null at the root level.
     * @param  string|null  $preferredValue  The value to prefer at this level, if a rule matched.
     * @return OrganizationNode The resolved or newly created node.
     *
     * @throws ValidationException If creating a new node fails validation (see CreateOrganizationNode::handle()).
     */
    private function resolveNode(OrganizationLevel $level, ?OrganizationNode $parent, ?string $preferredValue): OrganizationNode
    {
        if ($level->isLeaf()) {
            return $this->createOrganizationNode->handle($level, $parent, $preferredValue);
        }

        if ($preferredValue !== null) {
            $preferred = $this->siblingByValue($level, $parent, $preferredValue);

            if ($preferred === null) {
                return $this->createOrganizationNode->handle($level, $parent, $preferredValue);
            }

            if ($this->hasRoomBelow($preferred, $level)) {
                return $preferred;
            }
        }

        $existing = $this->siblingWithRoomBelow($level, $parent);

        if ($existing !== null) {
            return $existing;
        }

        return $this->createOrganizationNode->handle($level, $parent);
    }

    /**
     * @param  OrganizationLevel  $level  The level to search within.
     * @param  OrganizationNode|null  $parent  The parent to search under, or null for root-level nodes.
     * @param  string  $value  The exact value to look for.
     * @return OrganizationNode|null The matching sibling, or null if none exists.
     */
    private function siblingByValue(OrganizationLevel $level, ?OrganizationNode $parent, string $value): ?OrganizationNode
    {
        return OrganizationNode::query()
            ->where('level_id', $level->id)
            ->when($parent === null, fn ($query) => $query->whereNull('parent_id'))
            ->when($parent !== null, fn ($query) => $query->where('parent_id', $parent->id))
            ->where('value', $value)
            ->first();
    }

    /**
     * @param  OrganizationLevel  $level  The level to search within.
     * @param  OrganizationNode|null  $parent  The parent to search under, or null for root-level nodes.
     * @return OrganizationNode|null The first sibling (ordered by creation) that still has room for a child, or null if none do.
     */
    private function siblingWithRoomBelow(OrganizationLevel $level, ?OrganizationNode $parent): ?OrganizationNode
    {
        /** @var Collection<int, OrganizationNode> $siblings */
        $siblings = OrganizationNode::query()
            ->where('level_id', $level->id)
            ->when($parent === null, fn ($query) => $query->whereNull('parent_id'))
            ->when($parent !== null, fn ($query) => $query->where('parent_id', $parent->id))
            ->orderBy('created_at')
            ->get();

        foreach ($siblings as $sibling) {
            if ($this->hasRoomBelow($sibling, $level)) {
                return $sibling;
            }
        }

        return null;
    }

    /**
     * @param  OrganizationNode  $node  The node to check for available capacity below it.
     * @param  OrganizationLevel  $level  The level $node belongs to.
     * @return bool True if $level has no child level, or its child level hasn't reached capacity under $node.
     */
    private function hasRoomBelow(OrganizationNode $node, OrganizationLevel $level): bool
    {
        $childLevel = $level->childLevel();

        if ($childLevel === null) {
            return true;
        }

        return ! $childLevel->capacityReached($node);
    }
}
