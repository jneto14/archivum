<?php

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
     * @param  array{document_type_id?: string|null, tag_ids?: array<int, string>, from?: string|null, to?: string|null}  $filters
     * @return LengthAwarePaginator<int, Document>
     */
    public function handle(Workspace $workspace, ?string $query, array $filters): LengthAwarePaginator
    {
        $tagIds = $this->scopedTagIds($workspace, $filters['tag_ids'] ?? []);

        return Document::search($query ?? '')
            ->where('workspace_id', $workspace->id)
            ->query(fn (Builder $builder) => $builder
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
    }

    /**
     * @param  array<int, string>  $tagIds
     * @return array<int, string>
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
