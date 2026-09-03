<?php

declare(strict_types=1);

namespace App\Services\Ocr;

use Illuminate\Support\Str;

/**
 * Reduces an attachment's extracted text to one 64-bit number that can be
 * compared against another's.
 *
 * The comparison this exists for is "is this the same document I already
 * filed", and the realistic way that happens here is the same page being
 * photographed or scanned twice — so a hash of the text is useless: OCR reads a
 * handful of characters differently on each pass and every byte-exact
 * comparison comes back negative.
 *
 * A SimHash instead maps similar inputs to similar outputs, so "the same text
 * with a few characters misread" lands a few bits away and "a different
 * document" lands roughly half the bits away. Comparing two of them is an XOR
 * and a bit count, which is cheap enough to run against a whole workspace.
 *
 * Everything is computed on word triples rather than single words: a shingle
 * carries the order of the text, so two documents built from the same
 * vocabulary stop colliding on vocabulary alone.
 *
 * ## Why numbers count for more
 *
 * The hard case is not two unrelated documents — those land ~30 bits apart on
 * their own. It is two invoices from the same supplier, which are the same
 * page of prose with a different number, a different date and a different
 * total. Measured plain, that pair sits about as close as a rescan does, and a
 * warning that fires on every monthly invoice is one nobody reads.
 *
 * Weighting the shingles that carry a digit pushes them apart: what
 * distinguishes two documents from the same template is precisely the numbers
 * on them. Measured on realistic samples, that moves the same-template pair
 * from ~12 bits to ~29 while a rescan of one page stays at 10-19, which is what
 * makes a single threshold able to separate them at all.
 */
class TextFingerprint
{
    /** @var int Words per shingle. Three is the usual compromise: enough to carry word order, short enough that a single misread word only damages three shingles. */
    private const SHINGLE_SIZE = 3;

    /** @var int What a shingle containing a number counts for, against 1 for one that does not. See the note on this class. */
    private const NUMERIC_WEIGHT = 4;

    /**
     * Strip a text down to what two scans of the same page have in common.
     *
     * Case, accents and punctuation are exactly where OCR noise collects — a
     * comma read as a period, an accent lost to a poor exposure — and none of
     * them distinguish one document from another.
     *
     * @param string $text The raw extracted text.
     *
     * @return string Lowercase alphanumeric words separated by single spaces.
     */
    public function normalize(string $text): string
    {
        $ascii = Str::ascii(mb_strtolower($text));

        return mb_trim((string) preg_replace('/[^a-z0-9]+/', ' ', $ascii));
    }

    /**
     * Fingerprint a text, or decline to.
     *
     * @param string $text The raw extracted text.
     *
     * @return int|null The 64-bit fingerprint, or null if the text is too short to say anything about.
     */
    public function simhash(string $text): ?int
    {
        $shingles = $this->shingles($text);

        if (count($shingles) < (int) config('archivum.intake.duplicate_min_shingles')) {
            return null;
        }

        /** @var array<int, int> $weights One tally per bit: the shingles that set it, less those that left it clear, each counted by its weight. */
        $weights = array_fill(0, 64, 0);

        foreach ($shingles as $shingle) {
            $hash = $this->hash($shingle);
            $weight = preg_match('/\d/', $shingle) === 1 ? self::NUMERIC_WEIGHT : 1;

            for ($bit = 0; $bit < 64; $bit++) {
                $weights[$bit] += ((($hash >> $bit) & 1) === 1 ? 1 : -1) * $weight;
            }
        }

        $simhash = 0;

        for ($bit = 0; $bit < 64; $bit++) {
            if ($weights[$bit] > 0) {
                $simhash |= 1 << $bit;
            }
        }

        return $simhash;
    }

    /**
     * How far apart two fingerprints are, in bits.
     *
     * @param int $first One fingerprint.
     * @param int $second The other.
     *
     * @return int The Hamming distance, 0 (identical text) to 64.
     */
    public function distance(int $first, int $second): int
    {
        // decbin() renders a negative int as its full 64-bit two's complement,
        // which is what the sign bit of a fingerprint is. gmp_popcount would be
        // the direct way to say this, but the ext is not in the image.
        return mb_substr_count(decbin($first ^ $second), '1');
    }

    /**
     * Hash one shingle to a full 64-bit integer.
     *
     * Assembled byte by byte rather than through `unpack('J')`: the eighth
     * shift carries into the sign bit, which is exactly the value wanted, and
     * this way the result is an `int` by construction instead of an offset into
     * an array the type system has to be told cannot be missing.
     *
     * @param string $shingle The words to hash.
     *
     * @return int The hash, as a signed 64-bit integer.
     */
    private function hash(string $shingle): int
    {
        $bytes = hash('xxh64', $shingle, true);
        $hash = 0;

        for ($index = 0; $index < 8; $index++) {
            $hash = ($hash << 8) | ord($bytes[$index]);
        }

        return $hash;
    }

    /**
     * Break a text into overlapping word triples.
     *
     * @param string $text The raw extracted text.
     *
     * @return list<string> The shingles, in the order they appear.
     */
    private function shingles(string $text): array
    {
        $words = array_values(array_filter(
            explode(' ', $this->normalize($text)),
            fn (string $word): bool => $word !== '',
        ));

        $shingles = [];

        for ($index = 0; $index + self::SHINGLE_SIZE <= count($words); $index++) {
            $shingles[] = implode(' ', array_slice($words, $index, self::SHINGLE_SIZE));
        }

        return $shingles;
    }
}
