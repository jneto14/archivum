<?php

declare(strict_types=1);

namespace App\Actions\Organization\Concerns;

use App\Enums\NodeValueStrategy;
use Illuminate\Validation\ValidationException;

trait ValidatesAlphabeticalCapacity
{
    /**
     * The practical maximum for an Alphabetical-strategy level: A through Z.
     * Beyond this, node values would overflow into double letters (AA, AB, …),
     * which doesn't make sense for a physical letter-divider system.
     */
    private const int ALPHABETICAL_MAX_CAPACITY = 26;

    /**
     * @param NodeValueStrategy $strategy The level's value strategy.
     * @param int|null $capacity The level's requested capacity.
     * @param string $field The error bag key to report a violation under.
     *
     * @return void No return value when valid.
     *
     * @throws ValidationException If $strategy is Alphabetical and $capacity exceeds 26.
     */
    private function assertAlphabeticalCapacityWithinRange(NodeValueStrategy $strategy, ?int $capacity, string $field = 'levels'): void
    {
        if ($strategy === NodeValueStrategy::Alphabetical && $capacity !== null && $capacity > self::ALPHABETICAL_MAX_CAPACITY) {
            throw ValidationException::withMessages([
                $field => __('organization.alphabetical_capacity_max'),
            ]);
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
    private function normalizeAlphabeticalCapacity(NodeValueStrategy $strategy, ?int $capacity): ?int
    {
        if ($strategy !== NodeValueStrategy::Alphabetical) {
            return $capacity;
        }

        return $capacity ?? self::ALPHABETICAL_MAX_CAPACITY;
    }
}
