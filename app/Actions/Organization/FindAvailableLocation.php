<?php

declare(strict_types=1);

namespace App\Actions\Organization;

use App\Enums\NodeValueStrategy;
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
        private readonly CountFiledDocuments $countFiledDocuments,
    ) {}

    /**
     * Find (or create) the first available leaf OrganizationNode for the
     * given criteria, following ApplyOrganizationRules → OrganizationNode.
     *
     * @param OrganizationScheme $scheme The scheme to resolve/create a location within.
     * @param array<string, string> $criteria Matcher key/value pairs used to look up a preferred placement via ApplyOrganizationRules.
     *
     * @return OrganizationNode The resolved (or newly created) leaf node.
     *
     * @throws LogicException If $scheme has no levels defined.
     * @throws ValidationException If creating a node along the resolved path fails validation (see CreateOrganizationNode::handle()).
     */
    public function handle(OrganizationScheme $scheme, array $criteria = []): OrganizationNode
    {
        $resolved = $this->walk($scheme, $criteria, persist: true);

        if ($resolved['node'] === null) {
            throw new LogicException('Resolving a location with persistence must produce a node.');
        }

        return $resolved['node'];
    }

    /**
     * Work out where handle() would file a document, without creating anything.
     *
     * Suggesting a location happens on a GET, so it must not leave a node behind:
     * a location that does not exist yet comes back as a path with no node, and is
     * only created if the user actually picks it.
     *
     * @param OrganizationScheme $scheme The scheme to resolve a location within.
     * @param array<string, string> $criteria Matcher key/value pairs used to look up a preferred placement via ApplyOrganizationRules.
     *
     * @return array{node: OrganizationNode|null, value: string, path: string} The existing leaf node with its own value and path, or a null node plus the value and path the location would be created at.
     *
     * @throws LogicException If $scheme has no levels defined.
     * @throws ValidationException If the location could not be resolved without creating one that fails validation (e.g. a level at capacity, or a Manual level no rule names a value for).
     */
    public function preview(OrganizationScheme $scheme, array $criteria = []): array
    {
        return $this->walk($scheme, $criteria, persist: false);
    }

    /**
     * Walk the scheme level by level, reusing an existing node wherever one has room
     * and otherwise creating one (when $persist) or projecting the value it would take.
     *
     * @param OrganizationScheme $scheme The scheme to walk.
     * @param array<string, string> $criteria Matcher key/value pairs used to look up a preferred placement via ApplyOrganizationRules.
     * @param bool $persist Whether missing nodes are created along the way.
     *
     * @return array{node: OrganizationNode|null, value: string, path: string} The resolved leaf node with its own value and path; the node is null only when $persist is false and the location does not exist yet.
     *
     * @throws LogicException If $scheme has no levels defined.
     * @throws ValidationException If a node along the resolved path cannot be created (see CreateOrganizationNode::resolveValue()).
     */
    private function walk(OrganizationScheme $scheme, array $criteria, bool $persist): array
    {
        $levels = $scheme->levels;

        if ($levels->isEmpty()) {
            throw new LogicException('Cannot find an available location for a scheme with no levels.');
        }

        $rule = $this->applyOrganizationRules->handle($scheme, $criteria);

        $node = null;
        /** @var array<int, string> $pending Values for the levels that would have to be created, root first. */
        $pending = [];

        foreach ($levels as $level) {
            $preferredValue = $this->preferredValueFor($rule, $level);

            // Once a level has to be created, everything below it is new too:
            // a node that does not exist yet cannot have children to reuse.
            if ($pending !== []) {
                $pending[] = $this->valueUnderNewParent($level, $preferredValue);

                continue;
            }

            $placement = $this->resolvePlacement($level, $node, $preferredValue);

            if ($placement['node'] !== null) {
                $node = $placement['node'];

                continue;
            }

            if ($persist) {
                $node = $this->createOrganizationNode->handle($level, $node, $placement['value']);

                continue;
            }

            $pending[] = $this->createOrganizationNode->resolveValue($level, $node, $placement['value']);
        }

        if ($pending === []) {
            // Every level resolved to an existing node, the last of them the leaf.
            return ['node' => $node, 'value' => $node->value, 'path' => $node->path()];
        }

        return [
            'node' => null,
            'value' => (string) end($pending),
            'path' => implode('-', [...($node !== null ? [$node->path()] : []), ...$pending]),
        ];
    }

    /**
     * @param array{level: OrganizationLevel, preferred_value: string}|null $rule The rule resolved by ApplyOrganizationRules, if any.
     * @param OrganizationLevel $level The level being resolved, to check against $rule's target level.
     *
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
     * Decide which node at $level under $parent to file into: an existing one preferred by
     * a rule, an existing one with room, or none — in which case a node has to be created,
     * and the value it should take comes back with it.
     *
     * @param OrganizationLevel $level The level to resolve a node at.
     * @param OrganizationNode|null $parent The parent node already resolved for the level above, or null at the root level.
     * @param string|null $preferredValue The value to prefer at this level, if a rule matched.
     *
     * @return array{node: OrganizationNode|null, value: string|null} The node to reuse, or a null node plus the value a new one should be created with (null to auto-generate it).
     */
    private function resolvePlacement(OrganizationLevel $level, ?OrganizationNode $parent, ?string $preferredValue): array
    {
        if ($preferredValue !== null) {
            $preferred = $this->siblingByValue($level, $parent, $preferredValue);

            if ($preferred === null) {
                return ['node' => null, 'value' => $preferredValue];
            }

            if ($this->hasRoom($preferred, $level)) {
                return ['node' => $preferred, 'value' => null];
            }
        }

        return ['node' => $this->siblingWithRoom($level, $parent), 'value' => null];
    }

    /**
     * @param OrganizationLevel $level The level to search within.
     * @param OrganizationNode|null $parent The parent to search under, or null for root-level nodes.
     * @param string $value The exact value to look for.
     *
     * @return OrganizationNode|null The matching sibling, or null if none exists.
     */
    private function siblingByValue(OrganizationLevel $level, ?OrganizationNode $parent, string $value): ?OrganizationNode
    {
        return $this->siblings($level, $parent)->firstWhere('value', $value);
    }

    /**
     * @param OrganizationLevel $level The level to search within.
     * @param OrganizationNode|null $parent The parent to search under, or null for root-level nodes.
     *
     * @return OrganizationNode|null The first sibling (ordered by creation) that still has room, or null if none do.
     */
    private function siblingWithRoom(OrganizationLevel $level, ?OrganizationNode $parent): ?OrganizationNode
    {
        return $this->siblings($level, $parent)->first(fn (OrganizationNode $sibling) => $this->hasRoom($sibling, $level));
    }

    /**
     * @param OrganizationLevel $level The level to list nodes of.
     * @param OrganizationNode|null $parent The parent to list children of, or null for root-level nodes.
     *
     * @return Collection<int, OrganizationNode> The level's nodes under $parent, oldest first.
     */
    private function siblings(OrganizationLevel $level, ?OrganizationNode $parent): Collection
    {
        return OrganizationNode::query()
            ->where('level_id', $level->id)
            ->when($parent === null, fn ($query) => $query->whereNull('parent_id'))
            ->when($parent !== null, fn ($query) => $query->where('parent_id', $parent->id))
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Determine whether a node can still take what its level holds: child nodes for an
     * intermediate level, documents for a leaf. Both are bounded by the same `capacity`,
     * read at the level of the thing being counted — a leaf level's capacity is how many
     * documents fit in one of its nodes.
     *
     * A leaf level with no capacity configured has no known room, so filing into it opens
     * a new node rather than piling documents into the first one indefinitely.
     *
     * @param OrganizationNode $node The node to check for available room.
     * @param OrganizationLevel $level The level $node belongs to.
     *
     * @return bool True if $node can take another child (or document, at a leaf level).
     */
    private function hasRoom(OrganizationNode $node, OrganizationLevel $level): bool
    {
        $childLevel = $level->childLevel();

        if ($childLevel !== null) {
            return !$childLevel->capacityReached($node);
        }

        if ($level->capacity === null) {
            return false;
        }

        return $this->countFiledDocuments->at($node) < $level->capacity;
    }

    /**
     * Work out the value a level's node would take under a parent that does not exist yet,
     * and so has no siblings to count: the first value of the level's strategy, unless a
     * rule names one.
     *
     * @param OrganizationLevel $level The level the node would belong to.
     * @param string|null $preferredValue The value a matching rule names at this level, if any.
     *
     * @return string The value the node would be created with.
     *
     * @throws ValidationException If the level's strategy is Manual and no rule names a value, mirroring CreateOrganizationNode::resolveValue().
     */
    private function valueUnderNewParent(OrganizationLevel $level, ?string $preferredValue): string
    {
        if ($preferredValue !== null) {
            return $preferredValue;
        }

        if ($level->value_strategy === NodeValueStrategy::Manual) {
            throw ValidationException::withMessages([
                'value' => __('organization.value_required'),
            ]);
        }

        return $level->valueForPosition(1);
    }
}
