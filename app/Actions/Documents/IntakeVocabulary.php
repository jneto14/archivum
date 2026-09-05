<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Models\Document;
use App\Models\IntakeLabel;
use App\Support\Intake\ValueShape;
use Illuminate\Support\Str;

/**
 * What one workspace can read off a page, and what each of those things looks
 * like there.
 *
 * ## The metadata key is the kind
 *
 * There used to be a list of recognised kinds in the code — a tax number and a
 * vehicle registration — each with its own method and its own hand-written
 * format rule. That list is an assumption about what people archive, and it is
 * wrong for every archive of insurance policies, clinical records, building
 * permits or anything else nobody thought of. There is no way to enumerate it,
 * so nothing here tries.
 *
 * Metadata is already free-form key/value pairs (see docs/documents.md), which
 * means the archive has *already* named the things it holds. Whoever files
 * under "Nº de apólice" has said what that field is far more accurately than a
 * list in the code ever could. So a kind is just a metadata key, normalised.
 *
 * Two things are still named in code, and both earn it:
 *
 * - `document_date` and `amount` are recognised by their shape rather than by
 *   any word in front of them, so they are read the same way in every archive
 *   in every country and there is nothing about them to learn. See
 *   `SuggestDocumentMetadata`.
 * - The keys of `lang/{locale}/intake.php` seed the rest. Without them a new
 *   archive reads nothing at all until somebody has filled the same field in by
 *   hand on three documents — a feature for saving typing that requires the
 *   typing first. Those are language data, not a list of kinds: adding one is a
 *   translation, and a workspace can outgrow every one of them.
 */
class IntakeVocabulary
{
    /**
     * The kinds `SuggestDocumentMetadata` finds by shape rather than by label.
     *
     * They take no vocabulary and are never mined for any. A date is a date in
     * every language; the words in front of an amount are every heading on
     * every invoice, so learning from them would teach the reader that "total
     * due", "subtotal" and "qty" all introduce the number it wants.
     *
     * @var list<string>
     */
    public const array SHAPE_DRIVEN_KINDS = ['document_date', 'amount'];

    /**
     * Documents sampled to work out what a workspace's keys hold.
     *
     * A sample rather than the whole archive, because this is asked while a
     * document is being read. Recent ones, because a key that has not been
     * filled in for three hundred documents is not one the workspace is filing
     * by any more.
     */
    private const int DOCUMENT_SAMPLE = 300;

    /** @var array<string, array{shapes: array<string, ValueShape>, keys: array<string, string>}> What each workspace's keys hold — see filed(). */
    private array $filedByWorkspace = [];

    /** @var array<string, list<string>> Folded label words per "kind:workspace" — see labelsFor(). */
    private array $labels = [];

    /** @var array<string, list<string>> Kinds a workspace can read, per workspace — see readableKinds(). */
    private array $readable = [];

    /** @var array<string, list<string>> Normalised field-name aliases per kind, across every configured language — see aliasesFor(). */
    private array $aliases = [];

    /** @var list<string>|null Kinds the shipped language files carry vocabulary for — see seedKinds(). */
    private ?array $seedKinds = null;

    /** @var list<string>|null Kinds the shipped language files carry field-name aliases for — see aliasKinds(). */
    private ?array $aliasKinds = null;

    /**
     * Which kind of value a metadata key holds.
     *
     * Every key holds one, because the key *is* the kind. The only thing this
     * resolves is the handful of keys that mean something the application also
     * ships words for: a workspace field called "Nº Contribuinte" is the same
     * thing as one called "VAT number", and the two must not become two kinds
     * that learn separately and suggest into each other's fields.
     *
     * @param string $key The metadata key as the user wrote it.
     *
     * @return string The kind: a shipped one where the key is one of its known names, otherwise the normalised key itself.
     */
    public function kindForKey(string $key): string
    {
        $normalized = $this->normalizeKey($key);

        foreach ($this->aliasKinds() as $kind) {
            if (in_array($normalized, $this->aliasesFor($kind), true)) {
                return $kind;
            }
        }

        return $normalized;
    }

    /**
     * The kinds a workspace can read values for by label.
     *
     * The shipped ones, plus every kind this workspace has accepted a label
     * for. A kind with no labels is left out because it could only ever read
     * nothing: without a word to recognise it by there is no way to find a
     * value on a page, which is the whole design.
     *
     * @param string|null $workspaceId The workspace being read for, if any.
     *
     * @return list<string> The kinds, shipped ones first.
     */
    public function readableKinds(?string $workspaceId): array
    {
        $cacheKey = (string) $workspaceId;

        if (isset($this->readable[$cacheKey])) {
            return $this->readable[$cacheKey];
        }

        $kinds = $this->seedKinds();

        if ($workspaceId !== null) {
            /** @var list<string> $accepted */
            $accepted = IntakeLabel::query()
                ->accepted()
                ->where('workspace_id', $workspaceId)
                ->distinct()
                ->pluck('kind')
                ->all();

            foreach ($accepted as $kind) {
                if (!in_array($kind, $kinds, true)) {
                    $kinds[] = $kind;
                }
            }
        }

        return $this->readable[$cacheKey] = array_values(array_diff($kinds, self::SHAPE_DRIVEN_KINDS));
    }

    /**
     * Whether a kind is one the archive may be mined for words.
     *
     * Two things disqualify a key. A shape-driven kind has nothing to learn.
     * And a key whose filed values do not describe one kind of thing —
     * "Observações", "Assunto", any free-text field — must not be learned for
     * at all: with no shape to check a reading against, the words in front of
     * anything would become labels and the reader would start lifting
     * sentences off pages. The shipped kinds are exempt, because their
     * vocabulary is a promise the application already makes.
     *
     * @param string $kind The kind in question.
     * @param string|null $workspaceId The workspace whose filed values decide it.
     *
     * @return bool True if phrases in front of this kind's values may be offered as labels.
     */
    public function isMinable(string $kind, ?string $workspaceId): bool
    {
        if (in_array($kind, self::SHAPE_DRIVEN_KINDS, true)) {
            return false;
        }

        return in_array($kind, $this->seedKinds(), true)
            || isset($this->filed($workspaceId)['shapes'][$kind]);
    }

    /**
     * What values of this kind look like in this archive.
     *
     * @param string $kind The kind being read.
     * @param string|null $workspaceId The workspace whose filed values describe it.
     *
     * @return ValueShape The derived shape, or the generic one where the archive has not said.
     */
    public function shape(string $kind, ?string $workspaceId): ValueShape
    {
        return $this->filed($workspaceId)['shapes'][$kind] ?? ValueShape::generic();
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
    public function labelsFor(string $kind, ?string $workspaceId): array
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
                $labels[] = $this->fold((string) $label);
            }
        }

        if ($workspaceId !== null) {
            foreach (IntakeLabel::query()
                ->accepted()
                ->where('workspace_id', $workspaceId)
                ->where('kind', $kind)
                ->pluck('label') as $label) {
                $labels[] = $this->fold((string) $label);
            }
        }

        $labels = array_values(array_unique($labels));

        // Longest first, so "vat registration" is tried before "vat" and the
        // gap after the label is measured from the end of the longer phrase.
        usort($labels, static fn (string $first, string $second): int => mb_strlen($second) <=> mb_strlen($first));

        return $this->labels[$cacheKey] = $labels;
    }

    /**
     * The metadata key a suggestion of this kind should be written into, where
     * the document's own type offers nothing better.
     *
     * A shipped kind falls back to its own name, which is a field name the
     * application means: "amount", "tax_id". A learned kind falls back to how
     * this workspace actually spells it — the kind is a normalisation of a key
     * somebody typed, and offering to fill in "no_apolice" would be showing
     * them the machinery.
     *
     * @param string $kind The kind being suggested.
     * @param string|null $workspaceId The workspace being suggested into.
     *
     * @return string The key to write under.
     */
    public function keyFor(string $kind, ?string $workspaceId): string
    {
        if (in_array($kind, $this->aliasKinds(), true)) {
            return $kind;
        }

        return $this->filed($workspaceId)['keys'][$kind] ?? $kind;
    }

    /**
     * What to call a kind when showing it to somebody.
     *
     * A shipped kind has a name in the interface language, because the
     * application means something specific by it. A learned one is called
     * whatever this workspace spells it — the kind is a normalisation of a key
     * a person typed, and "no_apolice" is machinery rather than a field name.
     *
     * The current locale rather than all of them, unlike the labels: this is
     * read by one person in one language, where labels are matched against
     * pages that could be in any.
     *
     * @param string $kind The kind to name.
     * @param string|null $workspaceId The workspace whose spelling to fall back on.
     * @param string|null $filedAs The key as it was typed on the documents that taught this, where that was recorded.
     *
     * @return string The name to show.
     */
    public function nameFor(string $kind, ?string $workspaceId, ?string $filedAs = null): string
    {
        $name = trans('intake.names.' . $kind);

        if (is_string($name) && $name !== 'intake.names.' . $kind) {
            return $name;
        }

        // What was recorded beats what is sampled. A key that has not been
        // filed in the last few hundred documents falls out of the sample, and
        // a name that degrades to "auto_n" the moment an archive grows past it
        // is showing somebody the machinery.
        return $filedAs ?? $this->keyFor($kind, $workspaceId);
    }

    /**
     * Lowercase a word and strip its accents, so "Matrícula" and "MATRICULA"
     * are one thing.
     *
     * @param string $value The word as written.
     *
     * @return string The folded form.
     */
    public function fold(string $value): string
    {
        return Str::ascii(mb_strtolower($value));
    }

    /**
     * Reduce a key to the form the aliases are written in, so `Nº Contribuinte`
     * and `nif` are compared on equal terms.
     *
     * @param string $key The key as the user typed it.
     *
     * @return string Lowercase, unaccented, underscore-separated.
     */
    public function normalizeKey(string $key): string
    {
        return mb_trim((string) preg_replace('/[^a-z0-9]+/', '_', $this->fold($key)), '_');
    }

    /**
     * What each of a workspace's metadata keys is holding.
     *
     * One query for the whole workspace, memoised, because it is asked once per
     * kind while a document is being read and the answer is the same for all of
     * them.
     *
     * @param string|null $workspaceId The workspace to look at.
     *
     * @return array{shapes: array<string, ValueShape>, keys: array<string, string>} The derived shape per kind, for the keys whose values describe one, and the spelling each kind is most often written as.
     */
    private function filed(?string $workspaceId): array
    {
        if ($workspaceId === null) {
            return ['shapes' => [], 'keys' => []];
        }

        if (isset($this->filedByWorkspace[$workspaceId])) {
            return $this->filedByWorkspace[$workspaceId];
        }

        /** @var array<string, list<string>> $values */
        $values = [];

        /** @var array<string, array<string, int>> $spellings */
        $spellings = [];

        Document::query()
            ->where('workspace_id', $workspaceId)
            ->whereNotNull('metadata')
            // By id as well as by date: documents filed in one batch share a
            // timestamp, and an unbroken tie makes the sample — and every shape
            // derived from it — differ between two reads of the same archive.
            ->latest('created_at')
            ->latest('id')
            ->limit(self::DOCUMENT_SAMPLE)
            ->get(['id', 'metadata'])
            ->each(function (Document $document) use (&$values, &$spellings): void {
                foreach ($document->metadata ?? [] as $key => $value) {
                    if ((!is_string($value) && !is_int($value)) || blank($value)) {
                        continue;
                    }

                    $kind = $this->kindForKey((string) $key);

                    $values[$kind][] = (string) $value;
                    $spellings[$kind][(string) $key] = ($spellings[$kind][(string) $key] ?? 0) + 1;
                }
            });

        $shapes = [];

        foreach ($values as $kind => $filed) {
            $shape = ValueShape::fromValues($filed);

            if ($shape !== null) {
                $shapes[$kind] = $shape;
            }
        }

        $keys = [];

        foreach ($spellings as $kind => $counts) {
            arsort($counts);

            $keys[$kind] = (string) array_key_first($counts);
        }

        return $this->filedByWorkspace[$workspaceId] = ['shapes' => $shapes, 'keys' => $keys];
    }

    /**
     * The kinds the shipped language files carry label vocabulary for.
     *
     * Read from the translations rather than written down here, so that
     * teaching the application a new kind of document is a language file and
     * nothing else.
     *
     * @return list<string> The kinds, in the order the language files list them.
     */
    private function seedKinds(): array
    {
        return $this->seedKinds ??= $this->kindsListedUnder('intake.labels');
    }

    /**
     * The kinds the shipped language files carry field-name aliases for.
     *
     * A superset of the seeded ones: `amount` has names a user might have given
     * the field without having any words that introduce it on a page.
     *
     * @return list<string> The kinds.
     */
    private function aliasKinds(): array
    {
        return $this->aliasKinds ??= $this->kindsListedUnder('intake.aliases');
    }

    /**
     * @param string $group The translation group to read, e.g. `intake.labels`.
     *
     * @return list<string> The kinds any configured language lists under it.
     */
    private function kindsListedUnder(string $group): array
    {
        $kinds = [];

        /** @var array<string, string> $locales */
        $locales = config('archivum.locales', []);

        foreach (array_keys($locales) as $locale) {
            $translated = trans($group, [], (string) $locale);

            if (!is_array($translated)) {
                continue;
            }

            foreach (array_keys($translated) as $kind) {
                $kinds[(string) $kind] = true;
            }
        }

        return array_keys($kinds);
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
}
