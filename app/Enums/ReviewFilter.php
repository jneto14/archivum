<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which kind of finding the review queue is showing.
 *
 * The queue lists documents, and a document can be waiting for more than one
 * reason at once. After a bulk re-extraction it is usually waiting for one
 * reason across thousands of rows, and somebody working through the duplicates
 * should not have to scroll past the readings to find them (ARC-127).
 */
enum ReviewFilter: string
{
    case All = 'all';
    case Suggestions = 'suggestions';
    case Readings = 'readings';
    case Duplicates = 'duplicates';

    /**
     * @return self The filter applied when the request names none.
     */
    public static function default(): self
    {
        return self::All;
    }

    /**
     * @param string|null $value The value from the request.
     *
     * @return self The matching filter, or the default when it is absent or unknown.
     */
    public static function fromRequestValue(?string $value): self
    {
        return $value === null ? self::default() : (self::tryFrom($value) ?? self::default());
    }

    /**
     * @return list<string> Every value the request may carry.
     */
    public static function acceptedValues(): array
    {
        return array_column(self::cases(), 'value');
    }
}
