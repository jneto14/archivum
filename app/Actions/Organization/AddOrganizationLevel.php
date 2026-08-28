<?php

declare(strict_types=1);

namespace App\Actions\Organization;

use App\Actions\Organization\Concerns\ValidatesAlphabeticalCapacity;
use App\Enums\NodeValueStrategy;
use App\Models\OrganizationLevel;
use App\Models\OrganizationScheme;
use Illuminate\Validation\ValidationException;

class AddOrganizationLevel
{
    use ValidatesAlphabeticalCapacity;

    /**
     * Append a new level to the end of an existing OrganizationScheme.
     *
     * Levels can only ever be appended, never inserted in the middle: a node
     * can only exist at a level if every level before it also has nodes
     * (see CreateOrganizationNode::assertParentConsistency), so the levels
     * without nodes always form a contiguous block at the tail of the list.
     * Appending at the end keeps that invariant trivially true.
     *
     * @param OrganizationScheme $scheme The scheme to add the level to.
     * @param array{name: string, key: string, capacity?: int|null, value_strategy: NodeValueStrategy, display_settings?: array<string, mixed>|null, metadata?: array<string, mixed>|null} $level The new level's attributes.
     *
     * @return OrganizationLevel The newly created level.
     *
     * @throws ValidationException If $level's key is already used by another level in $scheme, or an Alphabetical-strategy level's capacity exceeds 26.
     */
    public function handle(OrganizationScheme $scheme, array $level): OrganizationLevel
    {
        $this->assertKeyIsUnique($scheme, $level['key']);
        $this->assertAlphabeticalCapacityWithinRange($level['value_strategy'], $level['capacity'] ?? null, 'capacity');

        $position = (int) $scheme->levels()->max('position') + 1;

        return OrganizationLevel::query()->create([
            'scheme_id' => $scheme->id,
            'name' => $level['name'],
            'key' => $level['key'],
            'position' => $position,
            'capacity' => $this->normalizeAlphabeticalCapacity($level['value_strategy'], $level['capacity'] ?? null),
            'value_strategy' => $level['value_strategy'],
            'display_settings' => $level['display_settings'] ?? null,
            'metadata' => $level['metadata'] ?? null,
        ]);
    }

    /**
     * @param OrganizationScheme $scheme The scheme to check against.
     * @param string $key The candidate level key.
     *
     * @return void No return value when $key is unique within $scheme.
     *
     * @throws ValidationException If $scheme already has a level with $key.
     */
    private function assertKeyIsUnique(OrganizationScheme $scheme, string $key): void
    {
        $exists = $scheme->levels()->where('key', $key)->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'key' => __('organization.duplicate_level_keys'),
            ]);
        }
    }
}
