<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Models\Document;
use App\Models\IntakeLabel;
use DateTimeImmutable;
use Illuminate\Support\Str;
use IntlDateFormatter;

/**
 * Reads a document's extracted text and proposes values for the fields it is
 * missing.
 *
 * Nothing here writes: the result is a list of proposals the form renders for
 * the user to accept or ignore, which is the whole point — a wrong value
 * filed silently is worse than an empty field.
 *
 * ## What is recognised, and how it stays portable
 *
 * A label, not a format. "VAT registration 501 234 567" is a tax number
 * because of the words in front of it, and reading it that way needs to know
 * nothing about which country issued it — where a rule written around the
 * Portuguese check digit rejects it outright, and a rule written around
 * Portuguese plate shapes reads no plate anywhere else on earth.
 *
 * So the country-specific formats are gone and the vocabulary lives in
 * `lang/{locale}/intake.php`. Adding a language to `archivum.locales` and
 * translating that file is the whole of adding a country; every configured
 * language is searched at once, because an archive holds an English invoice
 * and a Portuguese receipt side by side. Month names come from `intl` for the
 * same reason.
 *
 * The two kinds that need no vocabulary stay format-driven, because their
 * formats are not national: a date, and a number written to exactly two
 * decimals.
 *
 * A workspace can also add words of its own, mined from the values its users
 * filled in by hand and accepted by an admin — see LearnIntakeLabels. Those are
 * scoped to the workspace that accepted them and are read beside the shipped
 * ones, never instead of them.
 *
 * The cost is recall on a value printed with no label at all, which is rare —
 * and the failure it causes is silence, not a wrong suggestion.
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
     * The kinds recognised by the words in front of them rather than by their
     * own shape.
     *
     * Only these have vocabulary, so only these can be taught any — a date and
     * an amount are found by their form, and words learned for them would sit
     * unread. It also bounds the risk of learning: the phrases in front of a
     * tax number or a plate are specific ones, where the words in front of an
     * amount would be every heading on every invoice.
     *
     * @var list<string>
     */
    public const array LABEL_DRIVEN_KINDS = ['tax_id', 'vehicle_registration'];

    /** @var int How many recent documents of the same type are read to learn which keys that type actually uses. Enough to see the pattern, bounded so a large archive does not pay for it. */
    private const KEY_SAMPLE_SIZE = 50;

    /** @var array<string, list<string>> Keys in use, per "workspace:type" — see keysUsedByType(). */
    private array $keysByType = [];

    /** @var array<string, int>|null Month names to month numbers, across every configured language — see months(). */
    private ?array $months = null;

    /** @var array<string, list<string>> Folded label words per "kind:workspace" — see knownLabels(). */
    private array $labels = [];

    /** @var array<string, list<string>> Regex-quoted labels per "kind:workspace" — see labelsFor(). */
    private array $quotedLabels = [];

    /** @var array<string, list<string>> Normalised field-name aliases per kind, across every configured language — see aliasesFor(). */
    private array $aliases = [];

    /**
     * What may follow a label: separators, then groups of letters and digits
     * joined by single spaces, dots or dashes.
     *
     * Anchored, and applied to the text after a label rather than as part of one
     * big pattern with the labels alternated into it. That earlier construction
     * — an alternation, two word boundaries, a bounded gap and a nested
     * quantifier in a single expression — matched on PCRE 10.42 and did not on
     * the newer build CI runs, for one input out of six, with no error raised.
     * Simple pieces behave the same everywhere.
     *
     * `[ \t]` rather than `\h` for the same reason: the folded text is ASCII,
     * so the wider class buys nothing.
     */
    private const VALUE_PATTERN = '/^[ \t:.#\n-]{0,10}([a-z0-9]+(?:[ .-][a-z0-9]+){0,4})/';

    /**
     * Read every kind of value this recognises out of a text.
     *
     * The objective half of the job, and the half worth storing: what the page
     * says, regardless of which document it belongs to or what is already
     * filled in on it. Run once when extraction completes.
     *
     * @param string|null $text The extracted text.
     * @param string|null $workspaceId The workspace whose learned vocabulary to read with, if any.
     *
     * @return list<array{kind: string, value: string}> One entry per kind that matched, in a fixed order.
     */
    public function extract(?string $text, ?string $workspaceId = null): array
    {
        if (blank($text)) {
            return [];
        }

        $folded = $this->fold($text);

        $values = [
            'document_date' => $this->firstDate($folded),
            'amount' => $this->largestAmount($text),
            'tax_id' => $this->taxId($folded, $workspaceId),
            'vehicle_registration' => $this->vehicleRegistration($folded, $workspaceId),
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
        // not appear in the review queue until they are read again.
        return $this->resolve(
            $document,
            $document->metadata_suggestions ?? $this->extract($document->ocr_text, $document->workspace_id),
        );
    }

    /**
     * Read $document's text and store what is still worth suggesting.
     *
     * The pruning matters: `metadata_suggestions` is what the sidebar counts,
     * and it counts in SQL because it is asked on every request. Storing a
     * finding for a field that already has a value in it makes that count say
     * one thing while the review queue — which resolves properly — shows
     * another, and a badge that points at an empty page is worse than no badge.
     *
     * @param Document $document The document to read.
     *
     * @return void No return value; saves the document as a side effect.
     */
    public function record(Document $document): void
    {
        $findings = $this->extract($document->ocr_text, $document->workspace_id);

        $waiting = array_map(
            static fn (array $suggestion): string => $suggestion['kind'],
            $this->resolve($document, $findings),
        );

        $document->recordMetadataSuggestions(array_values(array_filter(
            $findings,
            static fn (array $finding): bool => in_array($finding['kind'], $waiting, true),
        )));
    }

    /**
     * Work out which field each finding belongs in, and drop the ones whose
     * field is no longer empty.
     *
     * Kept apart from the findings themselves because both answers change after
     * extraction ran: the document's type gains keys, and its fields get filled
     * in by hand.
     *
     * @param Document $document The document the findings belong to.
     * @param array<int, array{kind: string, value: string}> $findings What the text was found to contain.
     *
     * @return list<array{kind: string, key: string, value: string}> The findings still worth offering.
     */
    private function resolve(Document $document, array $findings): array
    {
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
     * Lowercase the text and strip its accents, so that "Março", "março" and
     * "marco" are one thing and a label written "Matrícula" matches a page that
     * prints "MATRICULA".
     *
     * Line by line, because `Str::ascii()` replaces a newline with a space.
     * Folded whole, the page becomes one long line — and every rule here that
     * relies on a value ending where its line does stops working: a label
     * reaches into the line below and takes the next field's value with it.
     *
     * @param string $text The extracted text.
     *
     * @return string The same text, folded, with its lines intact.
     */
    public function fold(string $text): string
    {
        /** @var list<string> $lines */
        $lines = preg_split('/\R/', $text) ?: [];

        return implode("\n", array_map(
            static fn (string $line): string => Str::ascii(mb_strtolower($line)),
            $lines,
        ));
    }

    /**
     * The first date in the text, as an ISO date.
     *
     * First rather than most recent or most frequent: a document states its own
     * date at the top, and everything below it — due dates, periods covered,
     * printed footers — is something else.
     *
     * `03/04/2026` is genuinely ambiguous and no label can resolve it, so the
     * order is configuration — `archivum.intake.date_order`, day-first by
     * default. It is the one thing here that a country still decides.
     *
     * @param string $folded The extracted text, lowercased and ASCII-folded.
     *
     * @return string|null The date as `Y-m-d`, or null if the text holds none.
     */
    private function firstDate(string $folded): ?string
    {
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

        $months = $this->months();
        $pattern = $this->monthPattern($months);

        // 14 March 2026, 14 de marco de 2026.
        preg_match_all(
            '/(\d{1,2})\h+(?:de\h+)?(' . $pattern . ')\.?,?\h+(?:de\h+)?(\d{4})/',
            $folded,
            $dayFirst,
            PREG_OFFSET_CAPTURE | PREG_SET_ORDER,
        );

        foreach ($dayFirst as $match) {
            $candidates[$match[0][1]] = $this->buildDate(
                (int) $match[3][0],
                $months[$match[2][0]],
                (int) $match[1][0],
            );
        }

        // March 14, 2026.
        preg_match_all(
            '/(' . $pattern . ')\.?\h+(\d{1,2})(?:st|nd|rd|th)?,?\h+(\d{4})/',
            $folded,
            $monthFirst,
            PREG_OFFSET_CAPTURE | PREG_SET_ORDER,
        );

        foreach ($monthFirst as $match) {
            $candidates[$match[0][1]] = $this->buildDate(
                (int) $match[3][0],
                $months[$match[1][0]],
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
     * Month names to month numbers, in every configured language.
     *
     * Read from `intl` rather than written down, so that adding a language to
     * `archivum.locales` teaches this its months for free — and so that a list
     * of two languages' months is not sitting in the code pretending to be
     * everybody's.
     *
     * @return array<string, int> Folded name to month number, spelled out and abbreviated.
     */
    private function months(): array
    {
        if ($this->months !== null) {
            return $this->months;
        }

        $months = [];

        /** @var array<string, string> $locales */
        $locales = config('archivum.locales', []);

        foreach (array_keys($locales) as $locale) {
            foreach (['MMMM', 'MMM'] as $width) {
                $formatter = new IntlDateFormatter(
                    (string) $locale,
                    IntlDateFormatter::NONE,
                    IntlDateFormatter::NONE,
                    null,
                    null,
                    $width,
                );

                for ($month = 1; $month <= 12; $month++) {
                    // Abbreviations come back with a trailing period in some
                    // languages ("mar."), which the pattern adds back itself.
                    $name = mb_rtrim(
                        (string) $formatter->format(new DateTimeImmutable(sprintf('2026-%02d-01', $month))),
                        '.',
                    );

                    $months[Str::ascii(mb_strtolower($name))] = $month;
                }
            }
        }

        return $this->months = $months;
    }

    /**
     * @param array<string, int> $months The month names to match.
     *
     * @return string An alternation for use inside a larger pattern, longest first so that `mar` cannot match the front of `march` and leave the rest of the word behind.
     */
    private function monthPattern(array $months): string
    {
        $names = array_keys($months);

        usort($names, static fn (string $first, string $second): int => mb_strlen($second) <=> mb_strlen($first));

        return implode('|', array_map(static fn (string $name): string => preg_quote($name, '/'), $names));
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
        // round it will be stored.
        if (mb_strlen((string) $parts[0]) === 4) {
            return $this->buildDate((int) $parts[0], (int) $parts[1], (int) $parts[2]);
        }

        return config('archivum.intake.date_order') === 'month'
            ? $this->buildDate((int) $parts[2], (int) $parts[0], (int) $parts[1])
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
     * Recognised by its label and nothing else — "VAT registration 501 234 567",
     * "NIF: 501442600", "Nº Contribuinte 501442600". A format rule cannot do
     * this job: every country writes the number differently, half of them put
     * letters in it, and a rule written around one country's check digit
     * rejects every other country's number outright.
     *
     * What the label cannot supply is a reason to believe the thing after it is
     * a number at all, so that much is checked here: enough digits, and not so
     * many characters that a sentence would qualify.
     *
     * @param string $folded The extracted text, lowercased and ASCII-folded.
     * @param string|null $workspaceId The workspace whose learned vocabulary to read with, if any.
     *
     * @return string|null The number, without its spacing, or null if the text holds none.
     */
    private function taxId(string $folded, ?string $workspaceId = null): ?string
    {
        // Letters allowed: a Spanish, Irish or Dutch VAT number carries them.
        foreach ($this->labelled($folded, 'tax_id', $workspaceId) as $candidate) {
            $value = mb_strtoupper((string) preg_replace('/[^a-z0-9]/', '', $candidate));
            $digits = (string) preg_replace('/\D/', '', $value);

            // Six digits keeps "tax number not applicable" out, and every real
            // one in: the shortest in Europe carries eight.
            if (mb_strlen($value) >= 8 && mb_strlen($value) <= 15 && mb_strlen($digits) >= 6) {
                return $value;
            }
        }

        return null;
    }

    /**
     * The first vehicle registration in the text.
     *
     * Also label-only, for the same reason: the plate shapes this used to match
     * were the four Portugal has issued since 1992, which read nothing off a
     * German, French or British document. What survives the loss of the shapes
     * is that a registration mixes letters and digits in a short token, which
     * is true of every country's and true of very little else.
     *
     * @param string $folded The extracted text, lowercased and ASCII-folded.
     * @param string|null $workspaceId The workspace whose learned vocabulary to read with, if any.
     *
     * @return string|null The registration as written, uppercased, or null if the text holds none.
     */
    private function vehicleRegistration(string $folded, ?string $workspaceId = null): ?string
    {
        foreach ($this->labelled($folded, 'vehicle_registration', $workspaceId) as $candidate) {
            $value = (string) preg_replace('/[^a-z0-9]/', '', $candidate);

            $mixed = preg_match('/\d/', $value) === 1 && preg_match('/[a-z]/', $value) === 1;

            if ($mixed && mb_strlen($value) >= 5 && mb_strlen($value) <= 8) {
                return mb_strtoupper(mb_trim($candidate));
            }
        }

        return null;
    }

    /**
     * Every value introduced by one of $kind's labels, in the order they appear.
     *
     * Only punctuation and space may sit between the label and the value, never
     * words: a gap that allows words allows a label to reach across a sentence
     * and claim something else's number. A document that writes "VAT
     * registration" is matched by having that whole phrase in the vocabulary,
     * which is also where a new one gets added.
     *
     * The value itself is groups of letters and digits joined by single spaces,
     * dots or dashes, which is what a number spaced "501 234 567" or a plate
     * written "12-AB-34" looks like — and which stops at the first thing that is
     * neither, rather than running on into the next line of prose.
     *
     * @param string $folded The extracted text, lowercased and ASCII-folded.
     * @param string $kind The kind whose labels to look for.
     * @param string|null $workspaceId The workspace whose learned vocabulary to read with, if any.
     *
     * @return list<string> The matched values, still to be validated by the caller.
     */
    private function labelled(string $folded, string $kind, ?string $workspaceId = null): array
    {
        $values = [];

        foreach ($this->labelsFor($kind, $workspaceId) as $label) {
            preg_match_all('/\b' . $label . '\b/', $folded, $found, PREG_OFFSET_CAPTURE);

            foreach ($found[0] as [$matched, $offset]) {
                // Byte offsets are safe to slice on: fold() has already reduced
                // the text to ASCII.
                $after = mb_substr($folded, $offset + mb_strlen($matched));

                // `??=`, because "VAT" and "VAT registration" both match here
                // and only the longer one is right. Labels arrive longest
                // first, so the first answer at a position is the best one.
                if (preg_match(self::VALUE_PATTERN, $after, $value) === 1) {
                    $values[$offset] ??= $value[1];
                }
            }
        }

        // Back into the order they appear on the page, which is the order the
        // caller takes its first acceptable answer from.
        ksort($values);

        return array_values($values);
    }

    /**
     * The label words for one kind: every configured language, plus whatever
     * this workspace has learned and accepted.
     *
     * All languages rather than the interface's: an archive holds an English
     * invoice and a Portuguese receipt side by side, and which one somebody
     * happens to be reading the application in says nothing about either.
     *
     * The learned half is scoped to the workspace on purpose. A phrase mined
     * from one archive's suppliers can be meaningless in another's, so a label
     * that turns out to be a bad one degrades the readings of the workspace
     * that accepted it and of nobody else. See LearnIntakeLabels.
     *
     * @param string $kind The kind whose labels are wanted.
     * @param string|null $workspaceId The workspace whose accepted labels to include, if any.
     *
     * @return list<string> The labels, folded, longest first.
     */
    public function knownLabels(string $kind, ?string $workspaceId = null): array
    {
        $cacheKey = $kind . ':' . ($workspaceId ?? '');

        if (isset($this->labels[$cacheKey])) {
            return $this->labels[$cacheKey];
        }

        $labels = [];

        /** @var array<string, string> $locales */
        $locales = config('archivum.locales', []);

        foreach (array_keys($locales) as $locale) {
            // A missing or mistranslated group comes back as the key itself
            // rather than a list, which is a language that simply contributes
            // no words — not a failure worth stopping for.
            $translated = trans('intake.labels.' . $kind, [], (string) $locale);

            if (!is_array($translated)) {
                continue;
            }

            foreach ($translated as $label) {
                $labels[] = Str::ascii(mb_strtolower((string) $label));
            }
        }

        if ($workspaceId !== null) {
            foreach (IntakeLabel::query()
                ->accepted()
                ->where('workspace_id', $workspaceId)
                ->where('kind', $kind)
                ->pluck('label') as $label) {
                $labels[] = Str::ascii(mb_strtolower((string) $label));
            }
        }

        $labels = array_values(array_unique($labels));

        // Longest first, so "vat registration" is tried before "vat" and the
        // gap after the label is measured from the end of the longer phrase.
        usort($labels, static fn (string $first, string $second): int => mb_strlen($second) <=> mb_strlen($first));

        return $this->labels[$cacheKey] = $labels;
    }

    /**
     * The same labels, ready to drop into a pattern.
     *
     * @param string $kind The kind whose labels are wanted.
     * @param string|null $workspaceId The workspace whose accepted labels to include, if any.
     *
     * @return list<string> The labels, regex-quoted, longest first.
     */
    private function labelsFor(string $kind, ?string $workspaceId = null): array
    {
        $cacheKey = $kind . ':' . ($workspaceId ?? '');

        return $this->quotedLabels[$cacheKey] ??= array_map(
            static fn (string $label): string => preg_quote($label, '/'),
            $this->knownLabels($kind, $workspaceId),
        );
    }

    /**
     * Which kind of value a metadata key is holding, if any.
     *
     * The inverse of resolveKey(): that one asks where a finding should land,
     * this one asks what a filled-in field was. Mining needs it to know that a
     * workspace's field called "Nº Contribuinte" holds tax numbers, and so that
     * the words in front of its values are candidate labels for that kind.
     *
     * @param string $key The metadata key as the user wrote it.
     *
     * @return string|null The kind it corresponds to, or null if it matches none.
     */
    public function kindForKey(string $key): ?string
    {
        $normalized = $this->normalizeKey($key);

        foreach (self::LABEL_DRIVEN_KINDS as $kind) {
            if (in_array($normalized, $this->aliasesFor($kind), true)) {
                return $kind;
            }
        }

        return null;
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
        $aliases = $this->aliasesFor($kind);

        foreach ($existingKeys as $key) {
            if (in_array($this->normalizeKey($key), $aliases, true)) {
                return $key;
            }
        }

        return $kind;
    }

    /**
     * The names a field of this kind might already be going by, from every
     * configured language at once — a workspace filing in Portuguese calls it
     * "valor" whatever the interface is set to.
     *
     * @param string $kind The kind whose aliases are wanted.
     *
     * @return list<string> The aliases, normalised the same way an existing key is.
     */
    private function aliasesFor(string $kind): array
    {
        if (isset($this->aliases[$kind])) {
            return $this->aliases[$kind];
        }

        $aliases = [$kind];

        /** @var array<string, string> $locales */
        $locales = config('archivum.locales', []);

        foreach (array_keys($locales) as $locale) {
            $translated = trans('intake.aliases.' . $kind, [], (string) $locale);

            if (!is_array($translated)) {
                continue;
            }

            foreach ($translated as $alias) {
                $aliases[] = $this->normalizeKey((string) $alias);
            }
        }

        return $this->aliases[$kind] = array_values(array_unique($aliases));
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
