<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How the free-text part of a document search is matched.
 *
 * These describe an intention — "every word I typed", "the words together" —
 * rather than the mechanism underneath. The modes this replaced were named
 * after MySQL's full-text index (natural language mode versus boolean mode
 * with a trailing wildcard), which is not a choice anybody wants to make, and
 * described only how the *attachment text* was matched while saying nothing
 * about the title. See ARC-125.
 *
 * Every mode but `TitleOnly` searches the title and the text extracted from
 * attachments together.
 */
enum SearchMode: string
{
    /**
     * Every word typed must appear somewhere in the document — in its title or
     * in the text of one of its scans — in any order. The default, and what
     * somebody typing two words almost always means.
     */
    case AllWords = 'all';

    /**
     * One of the words is enough. For a search where the archive's wording is
     * not known: "policy" or "insurance", whichever the document happens to
     * use.
     */
    case AnyWord = 'any';

    /**
     * The words together and in the order typed.
     */
    case Phrase = 'phrase';

    /**
     * The title only, ignoring what the scans say. For a large archive where
     * the document is known by name and the text would only add noise.
     */
    case TitleOnly = 'title';

    /**
     * The values this enum answered to before ARC-125, mapped onto their
     * closest surviving mode.
     *
     * `docs/search.md` promises that a filtered view can be linked, bookmarked
     * and reloaded, so a saved URL carrying `mode=exact` has to keep working
     * rather than come back as a validation error. Both legacy modes land on
     * `AllWords`: `broad` *is* the new all-words behaviour, and `exact` was the
     * same intention with two defects that the rewrite removes.
     *
     * @var array<string, string>
     */
    public const LEGACY_VALUES = [
        'exact' => 'all',
        'broad' => 'all',
    ];

    /**
     * The mode a search runs in when the request does not name one.
     *
     * @return self The default mode.
     */
    public static function default(): self
    {
        return self::AllWords;
    }

    /**
     * Resolve the `mode` query parameter, accepting the values this enum used
     * to answer to.
     *
     * Validation has already rejected anything that is neither a current value
     * nor a legacy one, so an unresolvable value here means no mode was asked
     * for at all.
     *
     * @param string|null $value The raw `mode` parameter, or null when absent.
     *
     * @return self The mode to search in.
     */
    public static function fromRequestValue(?string $value): self
    {
        if ($value !== null && array_key_exists($value, self::LEGACY_VALUES)) {
            return self::from(self::LEGACY_VALUES[$value]);
        }

        return self::tryFrom((string) $value) ?? self::default();
    }

    /**
     * Every value the `mode` parameter accepts, current and legacy.
     *
     * @return list<string> The accepted values, for the validation rule.
     */
    public static function acceptedValues(): array
    {
        return [
            ...array_column(self::cases(), 'value'),
            ...array_keys(self::LEGACY_VALUES),
        ];
    }
}
