<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Enums\IntakeLabelStatus;
use App\Models\Document;
use App\Models\IntakeLabel;
use App\Models\Workspace;
use App\Support\Intake\ValueShape;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Mines a document for words the reader could be recognising values by.
 *
 * ## The signal
 *
 * `SuggestDocumentMetadata` reads a page by the words in front of a value, and
 * that vocabulary ships in `lang/{locale}/intake.php`. What it cannot do is get
 * better on its own: a document writing "Steuernummer", or a field nobody
 * thought of — a policy number, a patient reference — is simply not read, and
 * the only fix is for somebody to notice, report it and wait for a release.
 *
 * Meanwhile every document already holds both halves of the answer. When a user
 * fills in a field the reader missed, the archive keeps the extracted text and
 * the value they decided belonged in it. Find that value in that text, look at
 * the words immediately before it, and the page has told you what it calls the
 * thing.
 *
 * ## Why it runs per document rather than on a schedule
 *
 * This used to be a weekly sweep of every document in every workspace. Two
 * things were wrong with that. A user correcting a field waited up to a week to
 * be asked about it, by which time the connection between what they did and
 * what they are being asked is gone. And the cost grew with the size of the
 * archive rather than with how much of it changed — the same ten thousand
 * documents re-read every Monday to find what the fifty edited ones said.
 *
 * The signal has an exact moment: somebody saves metadata on a document, or
 * that document's text finishes extracting. Mining one document at that moment
 * is a regex over one page. So `learn()` is what normally runs, from
 * `LearnDocumentIntakeLabels`, and `handle()` survives only as the backfill for
 * an archive that was filled before any of this existed.
 *
 * Counting incrementally is what makes the evidence rows necessary. A recount
 * from scratch could not double-count; an increment can, the second time the
 * same document is edited. So which documents evidence a phrase is recorded
 * rather than how many, which makes re-mining a document idempotent by
 * construction — and gives an admin the documents themselves to judge a
 * candidate by, rather than a number asking to be trusted.
 *
 * ## What stops it learning nonsense
 *
 * A bad label is not a private mistake — it degrades every reading in the
 * workspace, confidently, and a word common in prose would match prose. Four
 * things stand in the way, in increasing order of importance:
 *
 * - Only keys whose filed values describe one kind of thing are mined at all.
 *   A free-text field has no shape to check a reading against, so learning for
 *   it would teach the reader to lift sentences off pages. See
 *   `IntakeVocabulary::isMinable()`.
 * - A phrase must recur across several documents in the same workspace before
 *   it is offered, so one supplier's layout cannot teach the archive anything
 *   on its own.
 * - The word touching the value must be long enough to be a word, which is what
 *   keeps "de", "no" and their kind from being proposed alone while leaving
 *   them usable inside a longer phrase.
 * - Nothing enters the vocabulary unaccepted. Everything here writes candidates
 *   for a workspace admin to answer on the review queue, and a rejection is
 *   recorded so the next document does not ask again.
 */
class LearnIntakeLabels
{
    /** Documents read per query, so a large archive is backfilled in bounded memory. */
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

    public function __construct(
        private readonly SuggestDocumentMetadata $suggest,
        private readonly IntakeVocabulary $vocabulary,
    ) {}

    /**
     * Learn from one document, replacing whatever it evidenced before.
     *
     * Replacing rather than adding, because a document is mined again every
     * time its metadata changes: a value corrected from one number to another
     * stops being evidence for the phrase in front of the old one.
     *
     * @param Document $document The document to read.
     *
     * @return void No return value; writes candidate labels and their evidence as a side effect.
     */
    public function learn(Document $document): void
    {
        if (blank($document->ocr_text) || blank($document->metadata)) {
            $this->forget($document);

            return;
        }

        $candidates = $this->candidatesIn($document);

        DB::transaction(function () use ($document, $candidates): void {
            $before = $this->evidencedBy($document);
            $after = [];

            foreach ($candidates as $candidate) {
                [$kind, $label] = explode("\0", $candidate, 2);

                $after[] = IntakeLabel::query()->firstOrCreate(
                    [
                        'workspace_id' => $document->workspace_id,
                        'kind' => $kind,
                        'label' => $label,
                    ],
                    ['status' => IntakeLabelStatus::Pending, 'support' => 0],
                )->id;
            }

            $this->replaceEvidence($document, $before, $after);
        });
    }

    /**
     * Drop everything a document was evidence for.
     *
     * Called when its text or its metadata goes away, and on the way through
     * `learn()` for a document that has nothing to say: what it taught was true
     * of a version of it that no longer exists.
     *
     * @param Document $document The document to forget.
     *
     * @return void No return value; deletes evidence and recounts as a side effect.
     */
    private function forget(Document $document): void
    {
        $before = $this->evidencedBy($document);

        if ($before === []) {
            return;
        }

        DB::transaction(function () use ($document, $before): void {
            $this->replaceEvidence($document, $before, []);
        });
    }

    /**
     * Mine a whole workspace, one document at a time.
     *
     * The backfill. Extraction and editing keep the vocabulary current by
     * themselves, so this exists for the documents that were filed before they
     * did — on an installation upgrading into this feature, that is the entire
     * archive, and without this it would learn only from what is filed from
     * today onwards.
     *
     * @param Workspace $workspace The workspace to mine.
     *
     * @return int How many candidates are now waiting to be answered.
     */
    public function handle(Workspace $workspace): int
    {
        Document::query()
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('ocr_text')
            ->whereNotNull('metadata')
            ->select(['id', 'workspace_id', 'metadata', 'ocr_text'])
            ->chunkById(self::DOCUMENT_CHUNK, function (Collection $documents): void {
                foreach ($documents as $document) {
                    $this->learn($document);
                }
            });

        return IntakeLabel::query()
            ->where('workspace_id', $workspace->id)
            ->offered()
            ->count();
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
     *
     * @return list<string> Phrases keyed "kind\0label".
     */
    private function candidatesIn(Document $document): array
    {
        $workspaceId = $document->workspace_id;
        $folded = $this->suggest->fold((string) $document->ocr_text);
        $found = [];

        foreach ($document->metadata ?? [] as $key => $value) {
            if (!is_string($value) && !is_int($value)) {
                continue;
            }

            $kind = $this->vocabulary->kindForKey((string) $key);

            if (!$this->vocabulary->isMinable($kind, $workspaceId)) {
                continue;
            }

            // A value that does not look like the others filed under this key
            // says nothing about what introduces one. This is where an "n/a"
            // typed into an identifier field stops.
            if (!$this->vocabulary->shape($kind, $workspaceId)->matches((string) $value)) {
                continue;
            }

            $pattern = $this->patternFor((string) $value);

            if ($pattern === null) {
                continue;
            }

            $known = $this->vocabulary->labelsFor($kind, $workspaceId);

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
        $squeezed = ValueShape::squeeze($value);

        if (mb_strlen($squeezed) < ValueShape::MIN_LENGTH) {
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
     * @param Document $document The document to look up.
     *
     * @return list<string> The ids of the labels this document currently evidences.
     */
    private function evidencedBy(Document $document): array
    {
        $ids = [];

        foreach (DB::table('intake_label_documents')->where('document_id', $document->id)->pluck('intake_label_id') as $id) {
            $ids[] = (string) $id;
        }

        return $ids;
    }

    /**
     * Swap one document's evidence rows for another set, and settle the counts.
     *
     * The support figure is derived from the rows rather than incremented
     * beside them, so it cannot drift out of step with what it claims to count
     * however many times a document is re-read.
     *
     * A label left evidenced by nothing is deleted, unless somebody has already
     * answered it: a rejection has to outlive its evidence, or the next document
     * to use the phrase would ask the same question again.
     *
     * @param Document $document The document whose evidence is being replaced.
     * @param list<string> $before The label ids it evidenced.
     * @param list<string> $after The label ids it evidences now.
     *
     * @return void No return value; writes the evidence table and the support counts as a side effect.
     */
    private function replaceEvidence(Document $document, array $before, array $after): void
    {
        $removed = array_diff($before, $after);
        $added = array_diff($after, $before);

        if ($removed !== []) {
            DB::table('intake_label_documents')
                ->where('document_id', $document->id)
                ->whereIn('intake_label_id', $removed)
                ->delete();
        }

        if ($added !== []) {
            DB::table('intake_label_documents')->insert(array_map(
                static fn (string $labelId): array => [
                    'intake_label_id' => $labelId,
                    'document_id' => $document->id,
                ],
                array_values($added),
            ));
        }

        $touched = array_values(array_unique([...$removed, ...$added]));

        if ($touched === []) {
            return;
        }

        IntakeLabel::query()
            ->whereIn('id', $touched)
            ->update([
                'support' => DB::raw(
                    '(select count(*) from intake_label_documents where intake_label_documents.intake_label_id = intake_labels.id)',
                ),
            ]);

        IntakeLabel::query()
            ->whereIn('id', $touched)
            ->pending()
            ->where('support', 0)
            ->delete();
    }
}
