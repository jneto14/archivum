<?php

namespace App\Actions\Organization;

use App\Models\OrganizationLevel;
use App\Models\OrganizationNode;
use App\Models\OrganizationScheme;
use Illuminate\Support\Collection;
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
     * @param  array<string, string>  $criteria
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
     * @param  array{level: OrganizationLevel, preferred_value: string}|null  $rule
     */
    private function preferredValueFor(?array $rule, OrganizationLevel $level): ?string
    {
        if ($rule === null || $rule['level']->id !== $level->id) {
            return null;
        }

        return $rule['preferred_value'];
    }

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

    private function siblingByValue(OrganizationLevel $level, ?OrganizationNode $parent, string $value): ?OrganizationNode
    {
        return OrganizationNode::query()
            ->where('level_id', $level->id)
            ->when($parent === null, fn ($query) => $query->whereNull('parent_id'))
            ->when($parent !== null, fn ($query) => $query->where('parent_id', $parent->id))
            ->where('value', $value)
            ->first();
    }

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

    private function hasRoomBelow(OrganizationNode $node, OrganizationLevel $level): bool
    {
        $childLevel = $level->childLevel();

        if ($childLevel === null) {
            return true;
        }

        return ! $childLevel->capacityReached($node);
    }
}
