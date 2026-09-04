<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Models\Document;
use Illuminate\Support\Str;

/**
 * Reads a document's extracted text and proposes values for the fields it is
 * missing.
 *
 * Nothing here writes: the result is a list of proposals the form renders for
 * the user to accept or ignore, which is the whole point — a wrong value
 * filed silently is worse than an empty field.
 *
 * The heuristics are deliberately precision-first. An amount is only an amount
 * if a currency marker sits next to it; a tax id only if its check digit
 * agrees. A suggestion that is usually right and occasionally absent is useful;
 * one that fires on every nine-digit number teaches people to ignore the
 * feature.
 *
 * ## Why the keys are resolved rather than fixed
 *
 * Document types carry no field schema — metadata is free-form key/value pairs
 * (see docs/documents.md) — so "the fields defined for this type" only exists
 * as the keys the workspace already uses on documents of that type. Each kind
 * therefore carries a list of aliases, and adopts a matching existing key when
 * it finds one, so a workspace whose invoices all say "total" is not handed a
 * second field called "amount".
 */
class SuggestDocumentMetadata
{
    /**
     * The kinds of value this can recognise, each with the metadata key it
     * falls back to and the key names it will adopt instead.
     *
     * `document_date` is the exception: it targets the document's own date
     * field rather than a metadata key, which is where a reader would look for
     * it, so it has no aliases to match.
     *
     * @var array<string, array{key: string, aliases: list<string>}>
     */
    private const KINDS = [
        'document_date' => [
            'key' => 'document_date',
            'aliases' => [],
        ],
        'amount' => [
            'key' => 'amount',
            'aliases' => ['amount', 'total', 'valor', 'montante', 'preco', 'price', 'value'],
        ],
        'tax_id' => [
            'key' => 'tax_id',
            'aliases' => ['tax_id', 'nif', 'nipc', 'vat', 'vat_number', 'contribuinte'],
        ],
        'vehicle_registration' => [
            'key' => 'vehicle_registration',
            'aliases' => ['vehicle_registration', 'matricula', 'plate', 'registration', 'vehicle'],
        ],
    ];

    /** @var int How many recent documents of the same type are read to learn which keys that type actually uses. Enough to see the pattern, bounded so a large archive does not pay for it. */
    private const KEY_SAMPLE_SIZE = 50;

    /**
     * Month names in both of the application's languages, spelled out and
     * abbreviated, ASCII-folded and lowercase to match the folded text.
     *
     * Real invoices write their date in words at least as often as in digits —
     * "Date: 14 March 2026" — and a numbers-only pattern reads nothing from them.
     *
     * @var array<string, int>
     */
    private const MONTHS = [
        'january' => 1, 'jan' => 1, 'janeiro' => 1,
        'february' => 2, 'feb' => 2, 'fevereiro' => 2, 'fev' => 2,
        'march' => 3, 'mar' => 3, 'marco' => 3,
        'april' => 4, 'apr' => 4, 'abril' => 4, 'abr' => 4,
        'may' => 5, 'maio' => 5, 'mai' => 5,
        'june' => 6, 'jun' => 6, 'junho' => 6,
        'july' => 7, 'jul' => 7, 'julho' => 7,
        'august' => 8, 'aug' => 8, 'agosto' => 8, 'ago' => 8,
        'september' => 9, 'sept' => 9, 'sep' => 9, 'setembro' => 9, 'set' => 9,
        'october' => 10, 'oct' => 10, 'outubro' => 10, 'out' => 10,
        'november' => 11, 'nov' => 11, 'novembro' => 11,
        'december' => 12, 'dec' => 12, 'dezembro' => 12, 'dez' => 12,
    ];

    /** @var array<string, list<string>> Keys in use, per "workspace:type" — see keysUsedByType(). */
    private array $keysByType = [];

    /**
     * Read every kind of value this recognises out of a text.
     *
     * The objective half of the job, and the half worth storing: what the page
     * says, regardless of which document it belongs to or what is already
     * filled in on it. Run once when extraction completes.
     *
     * @param string|null $text The extracted text.
     *
     * @return list<array{kind: string, value: string}> One entry per kind that matched, in a fixed order.
     */
    public function extract(?string $text): array
    {
        if (blank($text)) {
            return [];
        }

        $values = [
            'document_date' => $this->firstDate($text),
            'amount' => $this->largestAmount($text),
            'tax_id' => $this->taxId($text),
            'vehicle_registration' => $this->vehicleRegistration($text),
        ];

        $findings = [];

        foreach ($values as $kind => $value) {
            if ($value !== null) {
                $findings[] = ['kind' => $kind, 'value' => $value];
            }
        }

        return $findings;
    }

    /**
     * Suggest values for whichever of $document's fields are still empty.
     *
     * Which field a value belongs in, and whether that field is still empty,
     * are both worked out here rather than stored: the document's type may
     * gain keys and its fields may be filled in by hand long after extraction
     * ran, and a suggestion for a field that now has a value in it is noise.
     *
     * @param Document $document The document to suggest for.
     *
     * @return list<array{kind: string, key: string, value: string}> The suggestions, at most one per kind, for fields that are still empty.
     */
    public function handle(Document $document): array
    {
        // Falls back to reading the text where nothing was stored, which is
        // every document extracted before the column existed. Those simply do
        // not appear in the review queue until they are extracted again.
        $findings = $document->metadata_suggestions ?? $this->extract($document->ocr_text);

        if ($findings === []) {
            return [];
        }

        $existingKeys = $this->keysUsedByType($document);
        $metadata = $document->metadata ?? [];

        $suggestions = [];

        foreach ($findings as $finding) {
            ['kind' => $kind, 'value' => $value] = $finding;

            if ($kind === 'document_date') {
                if ($document->document_date === null) {
                    $suggestions[] = ['kind' => $kind, 'key' => 'document_date', 'value' => $value];
                }

                continue;
            }

            $key = $this->resolveKey($kind, $existingKeys);

            if (blank($metadata[$key] ?? null)) {
                $suggestions[] = ['kind' => $kind, 'key' => $key, 'value' => $value];
            }
        }

        return $suggestions;
    }

    /**
     * The first date in the text, as an ISO date.
     *
     * First rather than most recent or most frequent: a document states its own
     * date at the top, and everything below it — due dates, periods covered,
     * printed footers — is something else.
     *
     * Ambiguous `dd/mm` vs `mm/dd` is read day-first. This is a Portuguese
     * application; a document written the other way round is the exception, and
     * the user sees the value before accepting it.
     *
     * @param string $text The extracted text.
     *
     * @return string|null The date as `Y-m-d`, or null if the text holds none.
     */
    private function firstDate(string $text): ?string
    {
        // Folded to ASCII and lowercased so that "Março", "março" and "marco"
        // are one pattern. Only a date built from the parts is ever returned,
        // never a substring of this, so folding cannot corrupt the result — and
        // both passes run over the same string, so their offsets are comparable.
        $folded = Str::ascii(mb_strtolower($text));

        $candidates = [];

        // 14/03/2026, 14-03-2026, 2026-03-14.
        preg_match_all(
            '/(?<!\d)\d{1,4}[\/.-]\d{1,2}[\/.-]\d{1,4}(?!\d)/',
            $folded,
            $numeric,
            PREG_OFFSET_CAPTURE,
        );

        foreach ($numeric[0] as [$value, $offset]) {
            $candidates[$offset] = $this->parseNumericDate($value);
        }

        $months = $this->monthPattern();

        // 14 March 2026, 14 de marco de 2026.
        preg_match_all(
            '/(\d{1,2})\h+(?:de\h+)?(' . $months . ')\.?,?\h+(?:de\h+)?(\d{4})/',
            $folded,
            $dayFirst,
            PREG_OFFSET_CAPTURE | PREG_SET_ORDER,
        );

        foreach ($dayFirst as $match) {
            $candidates[$match[0][1]] = $this->buildDate(
                (int) $match[3][0],
                self::MONTHS[$match[2][0]],
                (int) $match[1][0],
            );
        }

        // March 14, 2026.
        preg_match_all(
            '/(' . $months . ')\.?\h+(\d{1,2})(?:st|nd|rd|th)?,?\h+(\d{4})/',
            $folded,
            $monthFirst,
            PREG_OFFSET_CAPTURE | PREG_SET_ORDER,
        );

        foreach ($monthFirst as $match) {
            $candidates[$match[0][1]] = $this->buildDate(
                (int) $match[3][0],
                self::MONTHS[$match[1][0]],
                (int) $match[2][0],
            );
        }

        ksort($candidates);

        foreach ($candidates as $date) {
            if ($date !== null) {
                return $date;
            }
        }

        return null;
    }

    /**
     * The month names, longest first so that `mar` cannot match the front of
     * `march` and leave the rest of the word behind.
     *
     * @return string An alternation for use inside a larger pattern.
     */
    private function monthPattern(): string
    {
        $names = array_keys(self::MONTHS);

        usort($names, static fn (string $first, string $second): int => mb_strlen($second) <=> mb_strlen($first));

        return implode('|', $names);
    }

    /**
     * Read a date written entirely in digits.
     *
     * @param string $candidate The matched text, e.g. `14/03/2026`.
     *
     * @return string|null The date as `Y-m-d`, or null if those numbers are not one.
     */
    private function parseNumericDate(string $candidate): ?string
    {
        $parts = (array) preg_split('#[/.-]#', $candidate);

        if (count($parts) !== 3) {
            return null;
        }

        // A four-digit first part is a year, and the date is already the way
        // round it will be stored; anything else is day-first.
        return mb_strlen((string) $parts[0]) === 4
            ? $this->buildDate((int) $parts[0], (int) $parts[1], (int) $parts[2])
            : $this->buildDate((int) $parts[2], (int) $parts[1], (int) $parts[0]);
    }

    /**
     * @param int $year The year.
     * @param int $month The month.
     * @param int $day The day.
     *
     * @return string|null The date as `Y-m-d`, or null if it is not a real one.
     */
    private function buildDate(int $year, int $month, int $day): ?string
    {
        return checkdate($month, $day, $year)
            ? sprintf('%04d-%02d-%02d', $year, $month, $day)
            : null;
    }

    /**
     * The largest money-shaped number in the text.
     *
     * Two decimal places is the whole test, and requiring a currency marker
     * beside it was too strict to be useful: on a real invoice the amounts sit
     * in a column under a heading, and OCR flattens the table into a list of
     * bare numbers with the currency nowhere near them. A number written to
     * exactly two decimals is money in almost every document this archive
     * holds; a quantity or an invoice number is not written that way.
     *
     * Largest, because the number a reader wants off an invoice is its total,
     * and the total is by construction no smaller than the lines above it.
     *
     * @param string $text The extracted text.
     *
     * @return string|null The amount as a plain decimal, or null if the text holds no money-shaped number.
     */
    private function largestAmount(string $text): ?string
    {
        // The lookarounds keep a date out of this: without them `14.03.2026`
        // offers up `14.03`. No `u` flag, because OCR output is not guaranteed
        // to be valid UTF-8 and a `u` pattern silently matches nothing at all
        // against a subject that is not.
        preg_match_all(
            '/(?<![\d.,])\d{1,3}(?:[.,\h]\d{3})*[.,]\d{2}(?![.,]?\d)/',
            $text,
            $matches,
        );

        $largest = null;

        foreach ($matches[0] as $match) {
            $amount = $this->parseAmount($match);

            if ($amount !== null && ($largest === null || $amount > $largest)) {
                $largest = $amount;
            }
        }

        return $largest === null ? null : number_format($largest, 2, '.', '');
    }

    /**
     * Read a written amount as a number.
     *
     * The separators cannot be assumed: the same archive holds `1.250,50` from
     * a Portuguese supplier and `1,250.50` from an English-language one. The
     * last separator followed by exactly two digits is the decimal point;
     * everything else is grouping.
     *
     * @param string $written The amount as it appears in the text, currency marker included.
     *
     * @return float|null The value, or null if it does not parse.
     */
    private function parseAmount(string $written): ?float
    {
        // The currency marker and any spacing go first, so that `1.250,50 EUR`
        // and `€ 1 250,50` both arrive here as the same string of digits and
        // separators.
        $digits = (string) preg_replace('/[^0-9.,]/', '', $written);

        if ($digits === '') {
            return null;
        }

        $decimals = '';

        if (preg_match('/^(.*)[.,](\d{2})$/', $digits, $match) === 1) {
            $digits = $match[1];
            $decimals = '.' . $match[2];
        }

        $whole = (string) preg_replace('/[^0-9]/', '', $digits);

        return $whole === '' ? null : (float) ($whole . $decimals);
    }

    /**
     * The first tax number in the text.
     *
     * Two ways in, because there are two kinds of document. One says what the
     * number is — "VAT registration 501 234 567", "NIF: 501442600" — and a
     * label is proof enough on its own, whichever country's format follows it
     * and however it is spaced. The other just prints the digits, and there the
     * Portuguese check digit is what separates a tax number from an order
     * number, a phone number or a customer reference.
     *
     * @param string $text The extracted text.
     *
     * @return string|null The digits, or null if the text holds no tax number.
     */
    private function taxId(string $text): ?string
    {
        $folded = Str::ascii(mb_strtolower($text));

        $labelled = preg_match(
            '/\b(?:nif|nipc|vat|contribuinte|tax\h*(?:id|number))\b[^\n\d]{0,20}(\d[\d\h.]{6,14}\d)/',
            $folded,
            $match,
        );

        if ($labelled === 1) {
            $digits = (string) preg_replace('/\D/', '', $match[1]);

            if (mb_strlen($digits) >= 9 && mb_strlen($digits) <= 12) {
                return $digits;
            }
        }

        preg_match_all('/\b(?:pt\h*)?(\d{9})\b/', $folded, $matches);

        foreach ($matches[1] as $candidate) {
            if ($this->isPortugueseTaxNumber($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Whether nine digits carry a valid Portuguese check digit.
     *
     * Roughly ten in eleven arbitrary nine-digit numbers fail this, which is
     * what makes an unlabelled match worth offering at all.
     *
     * @param string $candidate Exactly nine digits.
     *
     * @return bool True if the ninth digit checks out against the first eight.
     */
    private function isPortugueseTaxNumber(string $candidate): bool
    {
        $sum = 0;

        for ($position = 0; $position < 8; $position++) {
            $sum += (int) $candidate[$position] * (9 - $position);
        }

        $remainder = $sum % 11;

        return ($remainder < 2 ? 0 : 11 - $remainder) === (int) $candidate[8];
    }

    /**
     * The first Portuguese vehicle registration in the text.
     *
     * All four plate shapes issued since 1992, dashes required: without them
     * the pattern matches any six characters of the right shape, and a scanned
     * page is full of those.
     *
     * @param string $text The extracted text.
     *
     * @return string|null The registration, uppercased, or null if the text holds none.
     */
    private function vehicleRegistration(string $text): ?string
    {
        $matched = preg_match(
            '/\b([A-Z]{2}-\d{2}-\d{2}|\d{2}-\d{2}-[A-Z]{2}|\d{2}-[A-Z]{2}-\d{2}|[A-Z]{2}-\d{2}-[A-Z]{2})\b/i',
            $text,
            $match,
        );

        return $matched === 1 ? mb_strtoupper($match[1]) : null;
    }

    /**
     * Pick the metadata key a suggestion should land in.
     *
     * Tolerant of a kind it does not know, because findings are read back from
     * a column written by an earlier version of this class.
     *
     * @param string $kind The kind of value being suggested.
     * @param list<string> $existingKeys Keys already used by this document's type, most used first.
     *
     * @return string A matching existing key, or the kind's default.
     */
    private function resolveKey(string $kind, array $existingKeys): string
    {
        $aliases = self::KINDS[$kind]['aliases'] ?? [];

        foreach ($existingKeys as $key) {
            if (in_array($this->normalizeKey($key), $aliases, true)) {
                return $key;
            }
        }

        return self::KINDS[$kind]['key'] ?? $kind;
    }

    /**
     * The metadata keys other documents of $document's type already use, most
     * common first.
     *
     * Memoised per type, because the review queue asks this for every document
     * on the page and they are mostly of the same few types — without it that
     * page is an N+1 that grows with how much there is to review.
     *
     * The excluded document is deliberately not part of the key: leaving one
     * document out of a sample of fifty cannot change which key wins, and
     * keying on it would defeat the memo entirely.
     *
     * @param Document $document The document whose type and workspace scope the search.
     *
     * @return list<string> The distinct keys, ordered by how many of those documents carry them.
     */
    private function keysUsedByType(Document $document): array
    {
        $cacheKey = $document->workspace_id . ':' . $document->document_type_id;

        if (isset($this->keysByType[$cacheKey])) {
            return $this->keysByType[$cacheKey];
        }

        $counts = [];

        Document::query()
            ->where('workspace_id', $document->workspace_id)
            ->where('document_type_id', $document->document_type_id)
            ->whereKeyNot($document->getKey())
            ->whereNotNull('metadata')
            ->latest('created_at')
            ->limit(self::KEY_SAMPLE_SIZE)
            ->get(['id', 'metadata'])
            ->each(function (Document $other) use (&$counts): void {
                foreach (array_keys($other->metadata ?? []) as $key) {
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                }
            });

        arsort($counts);

        return $this->keysByType[$cacheKey] = array_map('strval', array_keys($counts));
    }

    /**
     * Reduce a key to the form the aliases are written in, so `Nº Contribuinte`
     * and `nif` are compared on equal terms.
     *
     * @param string $key The key as the user typed it.
     *
     * @return string Lowercase, unaccented, underscore-separated.
     */
    private function normalizeKey(string $key): string
    {
        return mb_trim((string) preg_replace('/[^a-z0-9]+/', '_', Str::ascii(mb_strtolower($key))), '_');
    }
}
