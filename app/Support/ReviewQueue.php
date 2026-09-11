<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ReviewFilter;
use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;

/**
 * The documents a workspace is waiting on somebody to answer for.
 *
 * One object because three callers have to agree on what "waiting" means: the
 * page that lists them, the bulk answer that acts on "everything matching the
 * current filter", and the count that badges the sidebar. They were three
 * copies of the same conditions before, which is how a row could be counted
 * and not listed (ARC-127).
 *
 * Keyed by document rather than by finding. A document whose scan read badly
 * and which also has metadata to confirm was two rows in two differently
 * shaped sections, with nothing tying them together — tidy at three items and
 * most of why the page read as noise at four thousand.
 */
final readonly class ReviewQueue
{
    /**
     * @param ReviewFilter $filter Which kind of finding to list.
     */
    public function __construct(public ReviewFilter $filter = ReviewFilter::All) {}

    /**
     * The workspace's documents with something waiting, newest first.
     *
     * @param Workspace $workspace The workspace being reviewed.
     *
     * @return Builder<Document> The matching documents.
     */
    public function documents(Workspace $workspace): Builder
    {
        return Document::query()
            ->where('workspace_id', $workspace->id)
            ->where(fn (Builder $waiting) => $this->applyFilter($waiting));
    }

    /**
     * Narrow a query to documents waiting for the reason this filter names.
     *
     * `All` is an OR of the three rather than no condition at all: a document
     * with nothing waiting is not on this queue.
     *
     * @param Builder<Document> $query The query being narrowed.
     *
     * @return void The filter mutates $query in place.
     */
    private function applyFilter(Builder $query): void
    {
        match ($this->filter) {
            ReviewFilter::Suggestions => $this->whereHasSuggestions($query),
            ReviewFilter::Readings => $this->whereHasReadings($query),
            ReviewFilter::Duplicates => $this->whereHasDuplicates($query),
            ReviewFilter::All => $query
                ->where(fn (Builder $any) => $this->whereHasSuggestions($any))
                ->orWhere(fn (Builder $any) => $this->whereHasReadings($any))
                ->orWhere(fn (Builder $any) => $this->whereHasDuplicates($any)),
        };
    }

    /**
     * @param Builder<Document> $query The query being narrowed.
     *
     * @return void The condition is added in place.
     */
    private function whereHasSuggestions(Builder $query): void
    {
        // Length rather than "not null": an empty list is a document that has
        // been read and has nothing waiting, which is not the same as one
        // nothing has read yet. See Document::recordMetadataSuggestions().
        $query->whereRaw('json_length(documents.metadata_suggestions) > 0');
    }

    /**
     * @param Builder<Document> $query The query being narrowed.
     *
     * @return void The condition is added in place.
     */
    private function whereHasReadings(Builder $query): void
    {
        $query->whereIn('documents.id', DocumentAttachment::query()
            ->awaitingReadingReview()
            ->select('document_id'));
    }

    /**
     * @param Builder<Document> $query The query being narrowed.
     *
     * @return void The condition is added in place.
     */
    private function whereHasDuplicates(Builder $query): void
    {
        $query->whereIn('documents.id', DocumentAttachment::query()
            ->flaggedAsDuplicate()
            ->select('document_id'));
    }
}
