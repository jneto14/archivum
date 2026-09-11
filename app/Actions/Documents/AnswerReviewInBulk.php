<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Enums\BulkReviewAction;
use App\Enums\OcrReviewOutcome;
use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\Workspace;
use App\Support\ReviewQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class AnswerReviewInBulk
{
    public function __construct(private readonly ApplyMetadataSuggestions $apply) {}

    /**
     * Answer for many documents at once.
     *
     * The selection is either the documents named, or — when none are — every
     * document matching the filter the queue was built with. The second is the
     * case that matters after a re-extraction, where what somebody wants is
     * "all of it" and naming four thousand ids in a request body is not a way
     * to ask for that.
     *
     * Either way the selection is expressed as a subquery rather than resolved
     * into a list, so the two dismissals are one statement each however many
     * rows they touch. Accepting suggestions cannot be: the values differ per
     * document and are looked up again from what the application read, so it
     * is a chunked walk at roughly 3ms a document. On an archive with tens of
     * thousands of documents waiting at once that would outlive a request, and
     * the sweep machinery from ARC-122 is where it would have to go.
     *
     * @param Workspace $workspace The workspace being reviewed.
     * @param BulkReviewAction $action What to do with the selection.
     * @param ReviewQueue $queue The queue the selection was made against, which carries the active filter.
     * @param list<string>|null $documentIds The documents picked by hand, or null for everything the filter matches.
     *
     * @return int How many documents, readings or duplicates were answered for.
     */
    public function handle(
        Workspace $workspace,
        BulkReviewAction $action,
        ReviewQueue $queue,
        ?array $documentIds = null,
    ): int {
        $documents = $queue->documents($workspace)
            ->when($documentIds !== null, fn (Builder $query) => $query->whereIn('documents.id', $documentIds));

        return match ($action) {
            BulkReviewAction::AcceptSuggestions => $this->acceptSuggestions($documents),
            BulkReviewAction::DismissReadings => $this->dismissReadings($documents),
            BulkReviewAction::DismissDuplicates => $this->dismissDuplicates($documents),
        };
    }

    /**
     * Write every selected document's suggested values.
     *
     * Every kind is accepted, which is what the row already offers with its
     * boxes ticked — unticking one is the exception, and an exception is not
     * something a bulk action expresses. Somebody who wants to choose opens
     * the row.
     *
     * @param Builder<Document> $documents The selected documents.
     *
     * @return int How many documents were written.
     */
    private function acceptSuggestions(Builder $documents): int
    {
        $answered = 0;

        $documents->chunkById(100, function (Collection $chunk) use (&$answered): void {
            foreach ($chunk as $document) {
                $kinds = array_column($document->metadata_suggestions ?? [], 'kind');

                $this->apply->handle($document, $kinds);

                $answered++;
            }
        }, 'documents.id', 'id');

        return $answered;
    }

    /**
     * Take every unanswered reading under the selection off the queue.
     *
     * Recorded as `Dismissed` rather than as a confirmation. Nobody read these
     * — that is the whole reason for answering in bulk — and a reading marked
     * as vouched for by a person who never saw it is worse than one still
     * waiting (ARC-118).
     *
     * @param Builder<Document> $documents The selected documents.
     *
     * @return int How many readings were dismissed.
     */
    private function dismissReadings(Builder $documents): int
    {
        return $this->overDocuments($documents, fn (array $ids): int => DocumentAttachment::query()
            ->whereIn('document_id', $ids)
            ->awaitingReadingReview()
            ->update([
                'ocr_reviewed_at' => now(),
                'ocr_review_outcome' => OcrReviewOutcome::Dismissed,
            ]));
    }

    /**
     * Clear every duplicate warning under the selection.
     *
     * The same answer the single-row button gives — keep both copies — and
     * deliberately permanent, since a warning that returns on the next page
     * load has not been dismissed.
     *
     * @param Builder<Document> $documents The selected documents.
     *
     * @return int How many warnings were cleared.
     */
    private function dismissDuplicates(Builder $documents): int
    {
        return $this->overDocuments($documents, fn (array $ids): int => DocumentAttachment::query()
            ->whereIn('document_id', $ids)
            ->flaggedAsDuplicate()
            ->update(['duplicate_of_attachment_id' => null]));
    }

    /**
     * Run an update over the selected documents, a page of ids at a time.
     *
     * The ids are resolved rather than left as a subquery because the
     * selection is itself expressed in terms of `document_attachments` — a
     * document is on this queue partly *because* of the attachments under it
     * — and MySQL refuses to read the table an `UPDATE` is writing (error
     * 1093). Two statements per page instead of one, and the same result.
     *
     * `chunkById` rather than an offset walk: answering a page takes those
     * documents out of the filter, which would make every later offset skip
     * rows. Paging by id only ever moves forward.
     *
     * @param Builder<Document> $documents The selected documents.
     * @param callable(list<string>): int $update Applies the answer to one page of document ids.
     *
     * @return int How many rows were changed in total.
     */
    private function overDocuments(Builder $documents, callable $update): int
    {
        $changed = 0;

        $documents->select('documents.id')->chunkById(
            500,
            function (Collection $chunk) use (&$changed, $update): void {
                $changed += $update(array_values(array_map(strval(...), $chunk->pluck('id')->all())));
            },
            'documents.id',
            'id',
        );

        return $changed;
    }
}
