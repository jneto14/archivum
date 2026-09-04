<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Enums\IntakeLabelStatus;
use App\Models\Document;
use App\Models\IntakeLabel;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Mines a workspace's own documents for words it could be reading values by.
 *
 * ## The signal
 *
 * `SuggestDocumentMetadata` reads a page by the words in front of a value, and
 * that vocabulary ships in `lang/{locale}/intake.php`. What it cannot do is get
 * better on its own: a document writing "Steuernummer", or an abbreviation
 * nobody thought of, is simply not read, and the only fix is for somebody to
 * notice, report it and wait for a release.
 *
 * Meanwhile every document already holds both halves of the answer. When a user
 * fills in a field the reader missed, the archive keeps the extracted text and
 * the value they decided belonged in it. Find that value in that text, look at
 * the words immediately before it, and the page has told you what it calls the
 * thing.
 *
 * Nothing had to be captured up front for this: `ocr_text` and `metadata` are
 * both retained, so an archive that has been running for years can be mined the
 * day this is first run.
 *
 * ## What stops it learning nonsense
 *
 * A bad label is not a private mistake — it degrades every reading in the
 * workspace, confidently, and a word common in prose would match prose. Four
 * things stand in the way, in increasing order of importance:
 *
 * - Only the label-driven kinds are mined at all. The words in front of an
 *   amount would be every heading on every invoice; the words in front of a tax
 *   number are a short, specific list.
 * - A phrase must recur across several documents in the same workspace before
 *   it is offered, so one supplier's layout cannot teach the archive anything
 *   on its own.
 * - The word touching the value must be long enough to be a word, which is what
 *   keeps "de", "no" and their kind from being proposed alone while leaving
 *   them usable inside a longer phrase.
 * - Nothing enters the vocabulary unaccepted. Everything here writes candidates
 *   for a workspace admin to answer, and a rejection is recorded so the next run
 *   does not ask again.
 */
class LearnIntakeLabels
{
    /** Documents read per query, so a large archive is mined in bounded memory. */
    private const int DOCUMENT_CHUNK = 200;

    /** The longest candidate offered: "no contribuinte" is two, "vat registration number" three. */
    private const int MAX_LABEL_WORDS = 3;

    /**
     * How many characters the word touching the value must have.
     *
     * Three admits "vat", "nif" and "iva" — the abbreviations that are the whole
     * point — while refusing the two-letter connectives that would otherwise be
     * proposed on their own from every page that writes "de 501442600".
     */
    private const int MIN_ADJACENT_WORD_LENGTH = 3;

    /**
     * How much of a value must survive squeezing before it is worth hunting for.
     *
     * A short one matches by accident: four characters of a plate appear inside
     * a dozen unrelated numbers on the same page, and every one of those would
     * contribute whatever word happened to precede it.
     */
    private const int MIN_VALUE_LENGTH = 5;

    public function __construct(private readonly SuggestDocumentMetadata $suggest) {}

    /**
     * Read every document in $workspace and record what it suggests calling
     * things.
     *
     * @param Workspace $workspace The workspace to mine, and whose vocabulary any candidate joins.
     *
     * @return int How many candidates are now waiting to be answered.
     */
    public function handle(Workspace $workspace): int
    {
        /** @var array<string, int> $support Phrase, keyed "kind\0label", to the number of documents evidencing it. */
        $support = [];

        Document::query()
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('ocr_text')
            ->whereNotNull('metadata')
            ->select(['id', 'workspace_id', 'metadata', 'ocr_text'])
            ->chunkById(self::DOCUMENT_CHUNK, function (Collection $documents) use (&$support, $workspace): void {
                foreach ($documents as $document) {
                    foreach ($this->candidatesIn($document, $workspace) as $candidate) {
                        $support[$candidate] = ($support[$candidate] ?? 0) + 1;
                    }
                }
            });

        return $this->record($workspace, $support);
    }

    /**
     * The distinct phrases one document evidences.
     *
     * Distinct per document, not per occurrence: a page that prints its supplier
     * VAT number in the header and again in the footer is one archive saying one
     * thing, and counting it twice would let a single document clear a threshold
     * meant to require several.
     *
     * @param Document $document The document to read.
     * @param Workspace $workspace The workspace whose current vocabulary decides what is already known.
     *
     * @return list<string> Phrases keyed "kind\0label".
     */
    private function candidatesIn(Document $document, Workspace $workspace): array
    {
        $folded = $this->suggest->fold((string) $document->ocr_text);
        $found = [];

        foreach ($document->metadata ?? [] as $key => $value) {
            if (!is_string($value) && !is_int($value)) {
                continue;
            }

            $kind = $this->suggest->kindForKey((string) $key);

            if ($kind === null) {
                continue;
            }

            $pattern = $this->patternFor((string) $value);

            if ($pattern === null) {
                continue;
            }

            $known = $this->suggest->knownLabels($kind, $workspace->id);

            if (preg_match_all($pattern, $folded, $matches, PREG_OFFSET_CAPTURE) === false) {
                continue;
            }

            foreach ($matches[0] as [, $offset]) {
                foreach ($this->phrasesBefore($folded, (int) $offset) as $phrase) {
                    // Already read this way, whether it shipped in a language
                    // file or the workspace accepted it earlier.
                    if (in_array($phrase, $known, true)) {
                        continue;
                    }

                    $found[$kind . "\0" . $phrase] = true;
                }
            }
        }

        return array_keys($found);
    }

    /**
     * The phrases that could be introducing whatever sits at $offset.
     *
     * Only what is on the value's own line, and only across separators —
     * a phrase reaching over a line break, or across a word this does not
     * include, would be claiming something it does not introduce.
     *
     * @param string $folded The document's text, folded.
     * @param int $offset Where the value was found, as a byte offset into $folded.
     *
     * @return list<string> One phrase per length, from the word touching the value outwards.
     */
    private function phrasesBefore(string $folded, int $offset): array
    {
        $before = mb_substr($folded, 0, $offset);
        $lineStart = mb_strrpos($before, "\n");
        $line = $lineStart === false ? $before : mb_substr($before, $lineStart + 1);

        // The separators the reader itself allows between a label and a value.
        $line = (string) preg_replace('/[ \t:.#-]+$/', '', $line);

        /** @var list<string> $words */
        $words = array_values(array_filter((array) preg_split('/[^a-z0-9]+/', $line)));

        if ($words === []) {
            return [];
        }

        $adjacent = (string) end($words);

        // A value introduced by a number is not introduced by anything: that is
        // the previous field's value, or a line number.
        if (preg_match('/^[a-z]/', $adjacent) !== 1) {
            return [];
        }

        if (mb_strlen($adjacent) < self::MIN_ADJACENT_WORD_LENGTH) {
            return [];
        }

        $phrases = [];

        for ($length = 1; $length <= min(self::MAX_LABEL_WORDS, count($words)); $length++) {
            $phrase = implode(' ', array_slice($words, -$length));

            // A digit anywhere in the phrase means it has reached back into
            // another value rather than into words.
            if (preg_match('/\d/', $phrase) !== 1) {
                $phrases[] = $phrase;
            }
        }

        return $phrases;
    }

    /**
     * A pattern that finds $value in a page however that page spaced it.
     *
     * The value a user typed is rarely the string the page prints. A tax number
     * entered `501442600` appears as `501 442 600`; a plate entered `12-AB-34`
     * appears as `12 AB 34`. Both sides are reduced to their letters and digits,
     * and the separators a page might have used are allowed back between each
     * of them.
     *
     * @param string $value The value as it was filled in.
     *
     * @return string|null The pattern, or null if the value is too short to hunt for safely.
     */
    private function patternFor(string $value): ?string
    {
        $squeezed = (string) preg_replace(
            '/[^a-z0-9]/',
            '',
            Str::ascii(mb_strtolower($value)),
        );

        if (mb_strlen($squeezed) < self::MIN_VALUE_LENGTH) {
            return null;
        }

        $characters = array_map(
            static fn (string $character): string => preg_quote($character, '/'),
            mb_str_split($squeezed),
        );

        // Bounded quantifiers between single characters: no nesting, so nothing
        // here can backtrack pathologically over a long page.
        return '/' . implode('[ .\/-]{0,2}', $characters) . '/';
    }

    /**
     * Write the phrases that cleared the threshold, and count what is now
     * waiting.
     *
     * A phrase already answered keeps its answer: the evidence behind it is
     * refreshed and its status is left exactly where the admin put it. Without
     * that, every run would re-ask a question the workspace has already said no
     * to.
     *
     * @param Workspace $workspace The workspace the vocabulary belongs to.
     * @param array<string, int> $support Phrase, keyed "kind\0label", to the number of documents evidencing it.
     *
     * @return int How many candidates are waiting to be answered.
     */
    private function record(Workspace $workspace, array $support): int
    {
        $threshold = max(2, (int) config('archivum.intake.label_min_support', 3));

        foreach ($support as $candidate => $documents) {
            if ($documents < $threshold) {
                continue;
            }

            [$kind, $label] = explode("\0", $candidate, 2);

            $learned = IntakeLabel::query()->firstOrNew([
                'workspace_id' => $workspace->id,
                'kind' => $kind,
                'label' => $label,
            ]);

            if (!$learned->exists) {
                $learned->status = IntakeLabelStatus::Pending;
            }

            $learned->support = $documents;
            $learned->save();
        }

        return IntakeLabel::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', IntakeLabelStatus::Pending)
            ->count();
    }
}
