<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Models\Document;
use App\Models\Workspace;

/**
 * The metadata keys and values a workspace already uses, offered to the
 * document form as the user types.
 *
 * ## Why this exists at all
 *
 * Metadata is free-form key/value pairs, so nothing stops the same field being
 * filed as `NIF` on one document, `nif` on the next and `Nº Contribuinte` on
 * the one after. Once that has happened the three are unrelated as far as
 * search and comparison are concerned, and there is no point at which anybody
 * is told it is happening. Showing what the archive already says while the row
 * is being filled in is the only moment where the drift is cheap to avoid.
 *
 * This is not the same thing as `SuggestDocumentMetadata`, which reads values
 * off one document's own attachments. That proposes what *this* page says;
 * this proposes what the *workspace* has been saying.
 *
 * ## One spelling per field
 *
 * Keys are grouped by `IntakeVocabulary::kindForKey()` — the same normalisation
 * the reader uses — and each group is offered under the spelling the workspace
 * writes most often. Offering all three spellings of one field would hand the
 * user the drift as a menu, which is the opposite of the point.
 *
 * ## No cache
 *
 * One indexed query over a bounded sample, on a page that already runs several,
 * and the answer changes on every save. A cache here would buy microseconds and
 * cost a user their own key not appearing on the next document they file. If
 * the sample ever stops being affordable the fix is a derived table, not a TTL.
 */
class SuggestMetadataVocabulary
{
    /**
     * Documents sampled to work out what the workspace files.
     *
     * Recent ones rather than all of them, for the same reason
     * `IntakeVocabulary` samples: a key nobody has filled in for hundreds of
     * documents is not one the workspace is filing by any more, and the form
     * has to render while somebody waits for it.
     */
    private const int DOCUMENT_SAMPLE = 300;

    /**
     * Values offered per key.
     *
     * Values earn their place when a key has a small repeating set — an issuer,
     * a category, an account. A key whose values are all distinct is capped
     * here rather than excluded, because there is no reliable way to tell "all
     * distinct" from "distinct so far" and the cost of being wrong either way
     * is a list nobody picks from.
     */
    private const int VALUES_PER_KEY = 20;

    /**
     * @param IntakeVocabulary $vocabulary Decides which keys are spellings of one field.
     */
    public function __construct(public readonly IntakeVocabulary $vocabulary) {}

    /**
     * What this workspace files, most used first.
     *
     * @param Workspace $workspace The workspace whose documents are sampled.
     *
     * @return list<array{key: string, documentTypeIds: list<string>, values: list<string>}> One entry per field, under the spelling the workspace uses most, carrying the document types it appears on and its most common values.
     */
    public function handle(Workspace $workspace): array
    {
        /** @var array<string, array<string, int>> $spellings How often each kind is written each way. */
        $spellings = [];

        /** @var array<string, array<string, int>> $values How often each kind holds each value. */
        $values = [];

        /** @var array<string, array<string, true>> $types The document types each kind appears on. */
        $types = [];

        /** @var array<string, int> $counts How many sampled documents carry each kind. */
        $counts = [];

        Document::query()
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('metadata')
            // By id as well as by date, so a batch filed in one second does not
            // reorder the sample — and with it the tie-breaks below — between
            // two renders of the same form.
            ->latest('created_at')
            ->latest('id')
            ->limit(self::DOCUMENT_SAMPLE)
            ->get(['id', 'document_type_id', 'metadata'])
            ->each(function (Document $document) use (&$spellings, &$values, &$types, &$counts): void {
                foreach ($document->metadata ?? [] as $key => $value) {
                    if (blank($key)) {
                        continue;
                    }

                    $kind = $this->vocabulary->kindForKey((string) $key);

                    // The key counts whatever is under it. A field somebody
                    // filed and left empty is still a field this workspace
                    // uses, and dropping it is how the one place the
                    // suggestions matter — the empty row being added — ends up
                    // with nothing to offer: every key the document already
                    // holds is excluded there, so the vocabulary has to carry
                    // the ones it does not.
                    $spellings[$kind][(string) $key] = ($spellings[$kind][(string) $key] ?? 0) + 1;
                    $counts[$kind] = ($counts[$kind] ?? 0) + 1;
                    $types[$kind][$document->document_type_id] = true;

                    // The value is a separate judgement: only something that
                    // can be offered back as text goes into the value list.
                    if ((is_string($value) || is_int($value)) && filled($value)) {
                        $values[$kind][(string) $value] = ($values[$kind][(string) $value] ?? 0) + 1;
                    }
                }
            });

        // Sorts are stable in PHP 8, and the sample is walked newest first, so
        // everything tied here stays in most-recently-filed order rather than
        // in whatever order the array happened to be built.
        arsort($counts);

        $entries = [];

        foreach (array_keys($counts) as $kind) {
            $spelt = $spellings[$kind];
            arsort($spelt);

            // A key every document left empty has no values to offer, and is
            // still a key worth offering.
            $filed = $values[$kind] ?? [];
            arsort($filed);

            $entries[] = [
                'key' => (string) array_key_first($spelt),
                'documentTypeIds' => array_keys($types[$kind] ?? []),
                'values' => array_slice(array_map('strval', array_keys($filed)), 0, self::VALUES_PER_KEY),
            ];
        }

        return $entries;
    }
}
