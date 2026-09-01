<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Enums\SearchMode;
use App\Models\Document;
use App\Models\Tag;
use App\Models\Workspace;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator as ConcreteLengthAwarePaginator;

class SearchDocuments
{
    /**
     * Search Documents within a Workspace, combining free-text search over the
     * title and the text extracted from attachments with structured relational
     * filters.
     *
     * Workspace scoping is hard-enforced here and is never client-controlled
     * — this is the critical isolation guarantee for this Action.
     *
     * In `Exact` mode the matching is Scout's: `LIKE` over the title, and the
     * full-text index in natural language mode over `ocr_text`. In `Broad` mode
     * Scout is handed an empty query — which matches everything — and the text
     * predicate is built here instead, because Scout's strategy per column is a
     * static attribute on `Document::toSearchableArray()` and cannot vary per
     * request. Everything else, the workspace scoping and the filters included,
     * runs through one path either way.
     *
     * @param Workspace $workspace The workspace results are restricted to.
     * @param string|null $query Free-text search term; null/empty matches all.
     * @param array{document_type_id?: string|null, tag_ids?: array<int, string>, from?: string|null, to?: string|null} $filters Structured filters: document type, tag IDs, and document date range.
     * @param SearchMode $mode How $query is matched; see the enum.
     *
     * @return LengthAwarePaginator<int, Document> A paginated (15 per page) list of matching documents, eager-loaded with type, tags, current location, and creator.
     */
    public function handle(
        Workspace $workspace,
        ?string $query,
        array $filters,
        SearchMode $mode = SearchMode::Exact,
    ): LengthAwarePaginator {
        $tagIds = $this->scopedTagIds($workspace, $filters['tag_ids'] ?? []);
        $terms = $mode === SearchMode::Broad ? $this->terms($query) : [];

        $paginator = Document::search($mode === SearchMode::Broad ? '' : ($query ?? ''))
            ->where('workspace_id', $workspace->id)
            ->query(fn (Builder $builder) => $builder
                // Every column except `ocr_text`, which holds the full text of
                // a document's scans and is only ever read by the full-text
                // index in the WHERE clause. Selecting `documents.*` would drag
                // fifteen documents' worth of extracted pages into memory on
                // every listing. QueryBudgetTest counts queries, not bytes, so
                // it would not catch this.
                ->select([
                    'documents.id',
                    'documents.workspace_id',
                    'documents.document_type_id',
                    'documents.created_by',
                    'documents.title',
                    'documents.document_date',
                    'documents.metadata',
                    'documents.created_at',
                    'documents.updated_at',
                ])
                ->when(
                    $terms !== [],
                    fn (Builder $q) => $this->applyBroadSearch($q, $terms),
                )
                ->when(
                    $filters['document_type_id'] ?? null,
                    fn (Builder $q, string $typeId) => $q->where('document_type_id', $typeId),
                )
                ->when(
                    $filters['from'] ?? null,
                    fn (Builder $q, string $from) => $q->whereDate('document_date', '>=', $from),
                )
                ->when(
                    $filters['to'] ?? null,
                    fn (Builder $q, string $to) => $q->whereDate('document_date', '<=', $to),
                )
                ->when(
                    $tagIds !== [],
                    fn (Builder $q) => $q->whereHas('tags', fn (Builder $tags) => $tags->whereIn('tags.id', $tagIds)),
                )
                ->with(['documentType', 'tags', 'currentLocation.node', 'creator']))
            ->paginate(15);

        // Narrow the numbered page window from Laravel's default of three
        // either side, which is up to nine buttons — more than fits beside the
        // prev/next pair once the sidebar has taken its share of the width.
        //
        // Guarded because Scout types `paginate()` to the pagination contract,
        // which has no window control; only the concrete paginator does.
        if ($paginator instanceof ConcreteLengthAwarePaginator) {
            $paginator->onEachSide(1);
        }

        return $paginator->withQueryString();
    }

    /**
     * Require every term to appear somewhere in the document — in its title, or
     * in the text extracted from one of its attachments.
     *
     * Terms are ANDed and columns are ORed, so "fatur edp" finds a document
     * whose title carries one and whose scan carries the other. ORing the terms
     * instead would return most of the archive as soon as someone typed three
     * words.
     *
     * The title is matched as a substring, which is cheap on a short column.
     * The extracted text is matched with a trailing wildcard through the
     * full-text index rather than `LIKE '%term%'`, which could not use the index
     * and would scan every stored page. The cost of that choice is that only the
     * start of a word matches: "atura" will not find "fatura".
     *
     * The title clause is also what rescues terms the full-text index refuses —
     * anything shorter than `innodb_ft_min_token_size`, 3 characters by default.
     *
     * Typed against the base model rather than Document because that is what
     * Scout's `query()` callback hands over, and nothing here needs more.
     *
     * @param Builder<Model> $query The query to constrain.
     * @param list<string> $terms Sanitised search terms; see `terms()`.
     *
     * @return void No return value; the builder is constrained in place.
     */
    private function applyBroadSearch(Builder $query, array $terms): void
    {
        foreach ($terms as $term) {
            $query->where(fn (Builder $q) => $q
                ->where('documents.title', 'like', '%' . $term . '%')
                ->orWhereFullText('documents.ocr_text', $term . '*', ['mode' => 'boolean']));
        }
    }

    /**
     * Split a raw query into search terms safe to put in a boolean-mode
     * full-text expression.
     *
     * Splitting on everything that is not a letter or a digit does the
     * sanitising for free: boolean mode reads `+ - * " ( ) ~ < > @` as
     * operators, so a user typing "edp-2026" would otherwise be asking for
     * documents that contain "edp" and specifically *not* "2026". Here it
     * simply becomes two terms.
     *
     * Capped at eight terms, since each one costs its own index lookup and
     * nobody usefully searches for more.
     *
     * @param string|null $query The raw query string from the request.
     *
     * @return list<string> The terms to require, in the order they were typed.
     */
    private function terms(?string $query): array
    {
        $terms = preg_split('/[^\p{L}\p{N}]+/u', $query ?? '', -1, PREG_SPLIT_NO_EMPTY);

        return array_slice($terms === false ? [] : $terms, 0, 8);
    }

    /**
     * Restrict the given tag IDs to those that actually belong to the workspace, preventing cross-workspace tag filtering.
     *
     * @param Workspace $workspace The workspace the tags must belong to.
     * @param array<int, string> $tagIds Candidate tag IDs supplied by the caller.
     *
     * @return array<int, string> The subset of $tagIds that belong to $workspace.
     */
    private function scopedTagIds(Workspace $workspace, array $tagIds): array
    {
        if ($tagIds === []) {
            return [];
        }

        return Tag::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', $tagIds)
            ->pluck('id')
            ->all();
    }
}
