<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Models\Document;
use App\Models\Tag;
use App\Models\Workspace;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class SearchDocuments
{
    /**
     * Search Documents within a Workspace, combining Scout's free-text
     * search against `title` with structured relational filters.
     *
     * Workspace scoping is hard-enforced here and is never client-controlled
     * — this is the critical isolation guarantee for this Action.
     *
     * @param Workspace $workspace The workspace results are restricted to.
     * @param string|null $query Free-text search term matched against document titles via Scout; null/empty matches all.
     * @param array{document_type_id?: string|null, tag_ids?: array<int, string>, from?: string|null, to?: string|null} $filters Structured filters: document type, tag IDs, and document date range.
     *
     * @return LengthAwarePaginator<int, Document> A paginated (15 per page) list of matching documents, eager-loaded with type, tags, current location, and creator.
     */
    public function handle(Workspace $workspace, ?string $query, array $filters): LengthAwarePaginator
    {
        $tagIds = $this->scopedTagIds($workspace, $filters['tag_ids'] ?? []);

        return Document::search($query ?? '')
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
            ->paginate(15)
            ->withQueryString();
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
