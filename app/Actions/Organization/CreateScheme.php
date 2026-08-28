<?php

declare(strict_types=1);

namespace App\Actions\Organization;

use App\Enums\NodeValueStrategy;
use App\Models\OrganizationLevel;
use App\Models\OrganizationScheme;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateScheme
{
    /**
     * The practical maximum for an Alphabetical-strategy level: A through Z.
     * Beyond this, node values would overflow into double letters (AA, AB, …),
     * which doesn't make sense for a physical letter-divider system.
     */
    private const int ALPHABETICAL_MAX_CAPACITY = 26;

    /**
     * Create a new OrganizationScheme together with its ordered levels.
     *
     * @param Workspace $workspace The workspace the scheme belongs to.
     * @param string $name The scheme's name.
     * @param array<int, array{name: string, key: string, capacity?: int|null, value_strategy: NodeValueStrategy, display_settings?: array<string, mixed>|null, metadata?: array<string, mixed>|null}> $levels The scheme's levels in order; each entry's array position determines its position (1-indexed).
     *
     * @return OrganizationScheme The newly created scheme with its levels persisted.
     *
     * @throws ValidationException If $workspace already has a scheme, $levels is empty, $levels contains duplicate level keys, or an Alphabetical-strategy level's capacity exceeds 26.
     */
    public function handle(Workspace $workspace, string $name, array $levels): OrganizationScheme
    {
        $this->assertWorkspaceHasNoScheme($workspace);
        $this->assertLevelsAreConsistent($levels);
        $this->assertAlphabeticalCapacityWithinRange($levels);

        return DB::transaction(function () use ($workspace, $name, $levels): OrganizationScheme {
            $scheme = OrganizationScheme::query()->create([
                'workspace_id' => $workspace->id,
                'name' => $name,
            ]);

            foreach ($levels as $index => $level) {
                OrganizationLevel::query()->create([
                    'scheme_id' => $scheme->id,
                    'name' => $level['name'],
                    'key' => $level['key'],
                    'position' => $index + 1,
                    'capacity' => $this->normalizeCapacity($level['value_strategy'], $level['capacity'] ?? null),
                    'value_strategy' => $level['value_strategy'],
                    'display_settings' => $level['display_settings'] ?? null,
                    'metadata' => $level['metadata'] ?? null,
                ]);
            }

            return $scheme;
        });
    }

    /**
     * @param Workspace $workspace The workspace to check.
     *
     * @return void No return value when the workspace has no scheme yet.
     *
     * @throws ValidationException If $workspace already has an OrganizationScheme.
     */
    private function assertWorkspaceHasNoScheme(Workspace $workspace): void
    {
        $exists = OrganizationScheme::query()
            ->where('workspace_id', $workspace->id)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => __('organization.scheme_already_exists'),
            ]);
        }
    }

    /**
     * @param array<int, array{key: string}> $levels The levels to validate.
     *
     * @return void No return value when valid.
     *
     * @throws ValidationException If $levels is empty, or contains duplicate 'key' values.
     */
    private function assertLevelsAreConsistent(array $levels): void
    {
        if ($levels === []) {
            throw ValidationException::withMessages([
                'levels' => __('organization.levels_required'),
            ]);
        }

        $keys = array_column($levels, 'key');

        if (count($keys) !== count(array_unique($keys))) {
            throw ValidationException::withMessages([
                'levels' => __('organization.duplicate_level_keys'),
            ]);
        }
    }

    /**
     * @param array<int, array{value_strategy: NodeValueStrategy, capacity?: int|null}> $levels The levels to validate.
     *
     * @return void No return value when valid.
     *
     * @throws ValidationException If an Alphabetical-strategy level's capacity exceeds 26.
     */
    private function assertAlphabeticalCapacityWithinRange(array $levels): void
    {
        foreach ($levels as $level) {
            $capacity = $level['capacity'] ?? null;

            if ($level['value_strategy'] === NodeValueStrategy::Alphabetical && $capacity !== null && $capacity > self::ALPHABETICAL_MAX_CAPACITY) {
                throw ValidationException::withMessages([
                    'levels' => __('organization.alphabetical_capacity_max'),
                ]);
            }
        }
    }

    /**
     * Alphabetical-strategy levels default to a capacity of 26 (A–Z) when
     * none is given, instead of generating unboundedly into double letters.
     *
     * @param NodeValueStrategy $strategy The level's value strategy.
     * @param int|null $capacity The level's requested capacity.
     *
     * @return int|null The capacity to persist.
     */
    private function normalizeCapacity(NodeValueStrategy $strategy, ?int $capacity): ?int
    {
        if ($strategy !== NodeValueStrategy::Alphabetical) {
            return $capacity;
        }

        return $capacity ?? self::ALPHABETICAL_MAX_CAPACITY;
    }
}
