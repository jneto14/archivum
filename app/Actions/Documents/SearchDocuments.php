<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Enums\SearchMode;
use App\Models\Document;
use App\Models\OrganizationNode;
use App\Models\Tag;
use App\Models\Workspace;
use App\Support\TableSort;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator as ConcreteLengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SearchDocuments
{
    /** Most recently registered first: the listing is a work queue before it is a catalogue. */
    public const DEFAULT_SORT = 'created_at';

    public const DEFAULT_DIRECTION = 'desc';

    /**
     * The orders this listing offers, keyed as the interface names them.
     *
     * A document's location is not among them: it is the latest of its
     * assignments, resolved through a node's ancestors into a path assembled in
     * PHP, and no single column holds it.
     *
     * The type's name comes from a correlated subquery rather than a join, as it
     * does on every other listing that sorts by a related record. A join here
     * would have to be selected around: `document_types` also carries a
     * `workspace_id`, which the Scout workspace scoping references unqualified,
     * and `SELECT *` over a join hydrates the model from whichever `id` the
     * driver hands over last. Neither trap is visible at the call site, and both
     * are avoided by not joining.
     *
     * @return array<string, string|Expression> Sort key to the SQL it orders by.
     */
    public static function sortColumns(): array
    {
        return [
            'title' => 'documents.title',
            'document_date' => 'documents.document_date',
            'type' => DB::raw('(select name from document_types where document_types.id = documents.document_type_id)'),
            'created_at' => 'documents.created_at',
        ];
    }

    /**
     * Search Documents within a Workspace, combining free-text search over the
     * title and the text extracted from attachments with structured relational
     * filters.
     *
     * Workspace scoping is hard-enforced here and is never client-controlled
     * — this is the critical isolation guarantee for this Action.
     *
     * Scout is always handed an empty query — which matches everything — and
     * the text predicate is built here instead, because Scout's strategy per
     * column is a static attribute on `Document::toSearchableArray()` and
     * cannot vary per request. Everything else, the workspace scoping and the
     * filters included, runs through one path whatever the mode.
     *
     * Leaving one mode to Scout is what produced the two defects ARC-125 fixed.
     * Scout matches a non-full-text column with `LIKE '%<the entire query>%'`
     * (`DatabaseEngine::addTextSearchConstraints`), so `contrato arrendamento`
     * never matched a document titled *Contrato de arrendamento*; and it
     * queried `ocr_text` in natural language mode, which ORs the terms, so a
     * two-word search returned documents carrying only one of them. Building
     * every mode from the same per-term parts removes both by construction.
     *
     * @param Workspace $workspace The workspace results are restricted to.
     * @param string|null $query Free-text search term; null/empty matches all.
     * @param array{document_type_id?: string|null, tag_ids?: array<int, string>, from?: string|null, to?: string|null, node_id?: string|null} $filters Structured filters: document type, tag IDs, document date range, and physical location.
     * @param SearchMode $mode How $query is matched; see the enum.
     * @param TableSort|null $sort The order to return results in; the default order when null.
     *
     * @return LengthAwarePaginator<int, Document> A paginated (15 per page) list of matching documents, eager-loaded with type, tags, current location, and creator.
     */
    public function handle(
        Workspace $workspace,
        ?string $query,
        array $filters,
        ?SearchMode $mode = null,
        ?TableSort $sort = null,
    ): LengthAwarePaginator {
        $tagIds = $this->scopedTagIds($workspace, $filters['tag_ids'] ?? []);
        $nodeIds = $this->scopedNodeIds($workspace, $filters['node_id'] ?? null);
        $mode ??= SearchMode::default();
        $terms = $this->terms($query);
        $sort ??= self::defaultSort();

        $paginator = Document::search('')
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
                    fn (Builder $q) => $this->applyFreeText($q, $mode, $terms),
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
                // Where a document *is*, not where it has been: currentLocation
                // is the latest of its assignments, so a document that used to
                // sit here and was moved on does not come back.
                ->when(
                    $nodeIds !== null,
                    fn (Builder $q) => $q->whereHas(
                        'currentLocation',
                        fn (Builder $location) => $location->whereIn('organization_node_id', $nodeIds ?? []),
                    ),
                )
                // This query used to reach the database with no ORDER BY at all.
                // Scout's database engine appends one — but only for a model
                // with no full-text columns (`DatabaseEngine::paginateUsingDatabase`),
                // and `Document` declares `#[SearchUsingFullText(['ocr_text'])]`,
                // so that line never ran. Pagination over an unordered query
                // lets a document appear on two pages or on none, because each
                // page is a fresh query with a different OFFSET and nothing
                // obliges the database to arrange them the same way twice.
                ->tap(fn (Builder $q) => $sort->apply($q, 'documents.id'))
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
     * The order this listing takes when the caller has not asked for one.
     *
     * @return TableSort Most recently registered first, ties settled by id.
     */
    public static function defaultSort(): TableSort
    {
        return TableSort::of(self::sortColumns(), self::DEFAULT_SORT, self::DEFAULT_DIRECTION);
    }

    /**
     * Constrain the query to the documents the typed words should match, in
     * the way the chosen mode means.
     *
     * All four modes are assembled from the same two clauses — a substring
     * `LIKE` on the title and a boolean-mode full-text predicate on
     * `ocr_text` — and differ only in how those are combined. Keeping one set
     * of parts is what stops the modes drifting into matching different
     * columns from one another, which is the state ARC-125 found them in.
     *
     * Typed against the base model rather than Document because that is what
     * Scout's `query()` callback hands over, and nothing here needs more.
     *
     * @param Builder<Model> $query The query to constrain.
     * @param SearchMode $mode How the terms are combined.
     * @param list<string> $terms Sanitised search terms; see `terms()`.
     *
     * @return void No return value; the builder is constrained in place.
     */
    private function applyFreeText(Builder $query, SearchMode $mode, array $terms): void
    {
        match ($mode) {
            SearchMode::AllWords => $this->applyEveryTerm($query, $terms),
            SearchMode::AnyWord => $this->applyAnyTerm($query, $terms),
            SearchMode::Phrase => $this->applyPhrase($query, $terms),
            SearchMode::TitleOnly => $this->applyTitleOnly($query, $terms),
        };
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
     * The trailing wildcard is not a mode of its own any more. Measured on a
     * corpus built for ARC-125, it changes very little — "contrato" does not
     * start matching "contratada", nor "seguro" "segurado" — and what it does
     * add is longer words sharing a root, so "fatura" also finds "faturação".
     * In an archive that is wanted, which is why it is behaviour rather than a
     * question put to the user.
     *
     * The title clause is also what rescues terms the full-text index refuses —
     * anything shorter than `innodb_ft_min_token_size`, 3 characters by default.
     *
     * @param Builder<Model> $query The query to constrain.
     * @param list<string> $terms Sanitised search terms; see `terms()`.
     *
     * @return void No return value; the builder is constrained in place.
     */
    private function applyEveryTerm(Builder $query, array $terms): void
    {
        foreach ($terms as $term) {
            $query->where(fn (Builder $q) => $q
                ->where('documents.title', 'like', '%' . $term . '%')
                ->orWhereFullText('documents.ocr_text', $term . '*', ['mode' => 'boolean']));
        }
    }

    /**
     * Match a document carrying any one of the terms.
     *
     * The same two clauses as `applyEveryTerm()`, ORed across terms as well as
     * across columns. Deliberately broad: this is the mode for a search where
     * the archive's own wording is unknown, and narrowing it would defeat the
     * only reason to pick it.
     *
     * The whole disjunction is wrapped in one `where` group so it cannot leak
     * out and OR itself against the workspace scoping or the structured
     * filters, which would return the entire installation.
     *
     * @param Builder<Model> $query The query to constrain.
     * @param list<string> $terms Sanitised search terms; see `terms()`.
     *
     * @return void No return value; the builder is constrained in place.
     */
    private function applyAnyTerm(Builder $query, array $terms): void
    {
        $query->where(function (Builder $q) use ($terms): void {
            foreach ($terms as $term) {
                $q->orWhere('documents.title', 'like', '%' . $term . '%')
                    ->orWhereFullText('documents.ocr_text', $term . '*', ['mode' => 'boolean']);
            }
        });
    }

    /**
     * Match the terms adjacent and in the order they were typed.
     *
     * The phrase is rebuilt from the sanitised terms rather than taken from the
     * raw query, which both normalises the spacing and keeps `%` and `_` out of
     * the `LIKE` pattern — `terms()` has already dropped everything that is not
     * a letter or a digit. The consequence is that punctuation inside a phrase
     * is not matched literally: searching for the phrase "fatura edp" finds a
     * title reading "fatura edp" but not one reading "fatura, edp". Punctuation
     * is a separator everywhere else in this Action, and this is that same rule.
     *
     * No trailing wildcard here: a phrase that matched prefixes would not be the
     * phrase that was typed.
     *
     * MySQL's phrase search still goes through the index, so a phrase whose
     * words are shorter than `innodb_ft_min_token_size` cannot match inside
     * scan text. The title's `LIKE` is unaffected and covers the common case,
     * which is a phrase somebody remembers from a document's name.
     *
     * @param Builder<Model> $query The query to constrain.
     * @param list<string> $terms Sanitised search terms; see `terms()`.
     *
     * @return void No return value; the builder is constrained in place.
     */
    private function applyPhrase(Builder $query, array $terms): void
    {
        $phrase = implode(' ', $terms);

        $query->where(fn (Builder $q) => $q
            ->where('documents.title', 'like', '%' . $phrase . '%')
            ->orWhereFullText('documents.ocr_text', '"' . $phrase . '"', ['mode' => 'boolean']));
    }

    /**
     * Require every term in the title, ignoring what the scans say.
     *
     * The mode for an archive large enough that matching the text of every
     * stored page returns more than it helps. Terms are ANDed and each is a
     * substring, so "contrato arrendamento" finds *Contrato de arrendamento*
     * whichever order they were typed in.
     *
     * @param Builder<Model> $query The query to constrain.
     * @param list<string> $terms Sanitised search terms; see `terms()`.
     *
     * @return void No return value; the builder is constrained in place.
     */
    private function applyTitleOnly(Builder $query, array $terms): void
    {
        foreach ($terms as $term) {
            $query->where('documents.title', 'like', '%' . $term . '%');
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
     * Resolve the location filter into the node ids a matching document may be filed at:
     * the node itself and everything below it, so filtering by a cabinet answers with the
     * documents on all of its shelves.
     *
     * A node outside the workspace resolves to an empty set rather than being dropped:
     * dropping it would answer "the documents in that location" with the whole archive.
     *
     * @param Workspace $workspace The workspace the node must belong to.
     * @param string|null $nodeId The requested location, or null when not filtering by one.
     *
     * @return array<int, string>|null The node ids to match against, or null when not filtering by location.
     */
    private function scopedNodeIds(Workspace $workspace, ?string $nodeId): ?array
    {
        if ($nodeId === null) {
            return null;
        }

        $nodes = OrganizationNode::query()
            ->whereHas('level.scheme', fn (Builder $query) => $query->where('workspace_id', $workspace->id))
            ->get(['id', 'parent_id']);

        if ($nodes->doesntContain('id', $nodeId)) {
            return [];
        }

        $ids = [$nodeId];
        $frontier = [$nodeId];

        while ($frontier !== []) {
            $children = $nodes
                ->filter(fn (OrganizationNode $node) => in_array($node->parent_id, $frontier, true))
                ->pluck('id')
                ->all();

            $ids = [...$ids, ...$children];
            $frontier = $children;
        }

        return $ids;
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
