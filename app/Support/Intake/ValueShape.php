<?php

declare(strict_types=1);

namespace App\Support\Intake;

use Illuminate\Support\Str;

/**
 * What the values already filed under one metadata key look like, so that a
 * value read off a page can be checked against them.
 *
 * This is what replaced the hand-written format rules. Reading a tax number
 * used to mean `strlen >= 8 && strlen <= 15 && digits >= 6` sitting in the
 * code, which is a Portuguese assumption dressed as a general one, and reading
 * a policy number or a patient reference meant nothing at all — nobody knows
 * what papers an archive will hold.
 *
 * The archive knows, though. Whoever files under "Nº de apólice" has already
 * filed a dozen of them, and those values say how long the thing is, whether it
 * carries letters, and whether it is purely numeric. Counting characters over
 * what is already there needs no model and no country list, and it gets more
 * accurate as the archive grows rather than less.
 *
 * Two rules are not derived, because they are what makes any of this safe:
 *
 * - **A value must carry a digit.** It is the whole of what separates something
 *   a reader can lift off a page from prose. Without it a label sitting in
 *   front of "não aplicável" adopts those two words, and a free-text field
 *   teaches the reader to grab sentences.
 * - **A value must be at least MIN_LENGTH characters.** Shorter than that and a
 *   match is an accident: four characters appear inside a dozen unrelated
 *   numbers on the same page.
 */
final readonly class ValueShape
{
    /**
     * The shortest a value may be, once reduced to letters and digits.
     *
     * Five rather than four so that the fragments a label happens to sit in
     * front of — "at 20" out of "VAT at 20%" — fall under it. Every real
     * identifier this has been tried against is longer: the shortest European
     * plate squeezes to six.
     */
    public const int MIN_LENGTH = 5;

    /** Longer than this is a sentence rather than an identifier. */
    public const int MAX_LENGTH = 32;

    /** Distinct values a key needs before its shape is believed rather than guessed. */
    private const int MIN_DISTINCT_VALUES = 3;

    /**
     * How many groups of characters a value may be written in.
     *
     * Matches what the reader is willing to read after a label, and is the
     * second thing that keeps a free-text field out: an observation runs to
     * more words than a spaced-out reference number ever does.
     */
    private const int MAX_WORDS = 5;

    /**
     * How much longer the longest filed value may be than the shortest.
     *
     * A key holding one kind of thing holds them at comparable lengths. One
     * where the longest is triple the shortest is holding several kinds of
     * thing, or free text, and a shape derived from it would not narrow
     * anything.
     */
    private const int LENGTH_SPREAD = 2;

    /**
     * @param int $minLength The shortest a value may squeeze to.
     * @param int $maxLength The longest a value may squeeze to.
     * @param bool|null $hasLetters True where every filed value carries letters, false where none does, null where the archive is of both minds.
     */
    public function __construct(
        public int $minLength,
        public int $maxLength,
        public ?bool $hasLetters,
    ) {}

    /**
     * The shape to read a key by before the archive has said anything about it.
     *
     * Deliberately the widest thing that is still an identifier rather than
     * prose: long enough not to match by accident, short enough not to be a
     * sentence, and carrying a digit. A workspace filing its first document
     * reads with this; one that has filed a hundred reads with what those
     * hundred say.
     *
     * @return self The unnarrowed shape.
     */
    public static function generic(): self
    {
        return new self(self::MIN_LENGTH, self::MAX_LENGTH, null);
    }

    /**
     * Derive the shape of the values a key is holding, if they agree on one.
     *
     * Null is the answer for a key that is not holding one kind of thing —
     * "Observações", "Assunto", anything free-text. That is the consistency
     * filter, and it is why this returns null rather than a very wide shape:
     * a key whose values disagree should not be read off pages at all, not read
     * loosely.
     *
     * @param list<string> $values Every value filed under the key, as they were typed.
     *
     * @return self|null The shape, or null if those values do not describe one.
     */
    public static function fromValues(array $values): ?self
    {
        $lengths = [];
        $withLetters = 0;
        $seen = [];

        foreach ($values as $value) {
            $squeezed = self::squeeze($value);
            $length = mb_strlen($squeezed);

            if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
                return null;
            }

            if (preg_match('/\d/', $squeezed) !== 1) {
                return null;
            }

            if (count((array) preg_split('/\s+/', mb_trim($value), -1, PREG_SPLIT_NO_EMPTY)) > self::MAX_WORDS) {
                return null;
            }

            $lengths[] = $length;
            $seen[$squeezed] = true;

            if (preg_match('/[a-z]/', $squeezed) === 1) {
                $withLetters++;
            }
        }

        // Distinct, because the same value filed on ten documents is one
        // observation of what the key holds, not ten.
        if (count($seen) < self::MIN_DISTINCT_VALUES) {
            return null;
        }

        $shortest = min($lengths);
        $longest = max($lengths);

        if ($longest > $shortest * self::LENGTH_SPREAD) {
            return null;
        }

        return new self(
            // A character either way, because OCR drops and invents them, and
            // a range derived from perfectly typed values would reject the
            // reading of the very page it came from.
            max(self::MIN_LENGTH, $shortest - 1),
            min(self::MAX_LENGTH, $longest + 1),
            match (true) {
                $withLetters === count($lengths) => true,
                $withLetters === 0 => false,
                default => null,
            },
        );
    }

    /**
     * Whether a value read off a page could be one of these.
     *
     * @param string $value The candidate, as the page printed it.
     *
     * @return bool True if it fits.
     */
    public function matches(string $value): bool
    {
        $squeezed = self::squeeze($value);
        $length = mb_strlen($squeezed);

        if ($length < $this->minLength || $length > $this->maxLength) {
            return false;
        }

        if (preg_match('/\d/', $squeezed) !== 1) {
            return false;
        }

        $letters = preg_match('/[a-z]/', $squeezed) === 1;

        return $this->hasLetters === null || $this->hasLetters === $letters;
    }

    /**
     * Reduce a value to the letters and digits it is made of.
     *
     * The same value is written a dozen ways — `501442600`, `501 442 600`,
     * `501.442.600` — and the separators say nothing about what it is.
     *
     * @param string $value The value as written.
     *
     * @return string Lowercase letters and digits, nothing else.
     */
    public static function squeeze(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', Str::ascii(mb_strtolower($value)));
    }
}
