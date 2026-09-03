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
     * Suggest values for $document's empty fields from its extracted text.
     *
     * @param Document $document The document to suggest for; its `ocr_text` mirror is the source.
     *
     * @return list<array{kind: string, key: string, value: string}> The suggestions, at most one per kind, for fields that are still empty.
     */
    public function handle(Document $document): array
    {
        $text = $document->ocr_text;

        if (blank($text)) {
            return [];
        }

        $values = [
            'document_date' => $this->firstDate($text),
            'amount' => $this->largestAmount($text),
            'tax_id' => $this->taxId($text),
            'vehicle_registration' => $this->vehicleRegistration($text),
        ];

        $existingKeys = $this->keysUsedByType($document);
        $metadata = $document->metadata ?? [];

        $suggestions = [];

        foreach ($values as $kind => $value) {
            if ($value === null) {
                continue;
            }

            if ($kind === 'document_date') {
                if ($document->document_date !== null) {
                    continue;
                }

                $suggestions[] = ['kind' => $kind, 'key' => 'document_date', 'value' => $value];

                continue;
            }

            $key = $this->resolveKey($kind, $existingKeys);

            if (filled($metadata[$key] ?? null)) {
                continue;
            }

            $suggestions[] = ['kind' => $kind, 'key' => $key, 'value' => $value];
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
        preg_match_all('/\b\d{1,4}[\/.-]\d{1,2}[\/.-]\d{1,4}\b/', $text, $matches);

        foreach ($matches[0] as $candidate) {
            $parts = (array) preg_split('#[/.-]#', $candidate);

            if (count($parts) !== 3) {
                continue;
            }

            // A four-digit first part is a year, and the date is already the
            // way round it will be stored; anything else is day-first.
            [$year, $month, $day] = mb_strlen((string) $parts[0]) === 4
                ? [(int) $parts[0], (int) $parts[1], (int) $parts[2]]
                : [(int) $parts[2], (int) $parts[1], (int) $parts[0]];

            if (checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }

        return null;
    }

    /**
     * The largest currency amount in the text.
     *
     * Largest because the number a reader wants off an invoice is its total,
     * and the total is by construction no smaller than the lines above it.
     *
     * @param string $text The extracted text.
     *
     * @return string|null The amount as a plain decimal, or null if the text holds no amount next to a currency marker.
     */
    private function largestAmount(string $text): ?string
    {
        // Horizontal space only, and no `u` flag: OCR output is not guaranteed
        // to be valid UTF-8, and a `u` pattern silently matches nothing at all
        // against a subject that isn't. The euro sign matches as bytes either way.
        $number = '\d[\d.,\h]*\d|\d';

        preg_match_all(
            '/(?:€|EUR\b|euros?\b)\h*(?:' . $number . ')|(?:' . $number . ')\h*(?:€|EUR\b|euros?\b)/i',
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
     * The first Portuguese tax number in the text.
     *
     * The check digit is verified, which is what makes this usable at all: nine
     * digits on their own also describe order numbers, phone numbers and
     * customer references, and roughly ten in eleven of those fail the check.
     *
     * @param string $text The extracted text.
     *
     * @return string|null The nine digits, or null if the text holds no valid one.
     */
    private function taxId(string $text): ?string
    {
        preg_match_all('/\b(?:PT\s*)?(\d{9})\b/i', $text, $matches);

        foreach ($matches[1] as $candidate) {
            $sum = 0;

            for ($position = 0; $position < 8; $position++) {
                $sum += (int) $candidate[$position] * (9 - $position);
            }

            $remainder = $sum % 11;
            $checkDigit = $remainder < 2 ? 0 : 11 - $remainder;

            if ($checkDigit === (int) $candidate[8]) {
                return $candidate;
            }
        }

        return null;
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
     * @param string $kind The kind of value being suggested.
     * @param list<string> $existingKeys Keys already used by this document's type, most used first.
     *
     * @return string A matching existing key, or the kind's default.
     */
    private function resolveKey(string $kind, array $existingKeys): string
    {
        $aliases = self::KINDS[$kind]['aliases'];

        foreach ($existingKeys as $key) {
            if (in_array($this->normalizeKey($key), $aliases, true)) {
                return $key;
            }
        }

        return self::KINDS[$kind]['key'];
    }

    /**
     * The metadata keys other documents of $document's type already use, most
     * common first.
     *
     * @param Document $document The document whose type and workspace scope the search.
     *
     * @return list<string> The distinct keys, ordered by how many of those documents carry them.
     */
    private function keysUsedByType(Document $document): array
    {
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

        return array_map('strval', array_keys($counts));
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
