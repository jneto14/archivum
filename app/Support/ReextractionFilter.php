<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\OcrStatus;
use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which of a workspace's attachments a re-extraction should cover.
 *
 * The same choice is made in three places — the console command's options, the
 * task payload the sweep is dispatched with, and the query that selects the
 * rows — so it is one object rather than three sets of parameters that have to
 * agree (ARC-122).
 *
 * @phpstan-type FilterPayload array{document_id?: string|null, status?: string|null, only_unscored?: bool}
 */
final readonly class ReextractionFilter
{
    /**
     * @param string|null $documentId Restrict to one document's attachments.
     * @param OcrStatus|null $status Restrict to attachments currently in this state — `failed` being the common one.
     * @param bool $onlyUnscored Restrict to attachments read before per-word confidence existed, which is how an archive predating 0.4.0 is found.
     */
    public function __construct(
        public ?string $documentId = null,
        public ?OcrStatus $status = null,
        public bool $onlyUnscored = false,
    ) {}

    /**
     * Rebuild the filter a sweep was started with from its task payload.
     *
     * @param array<string, mixed> $payload The task's stored payload.
     *
     * @return self The filter the sweep should apply.
     */
    public static function fromPayload(array $payload): self
    {
        $status = $payload['status'] ?? null;

        return new self(
            documentId: is_string($payload['document_id'] ?? null) ? $payload['document_id'] : null,
            status: is_string($status) ? OcrStatus::tryFrom($status) : null,
            onlyUnscored: (bool) ($payload['only_unscored'] ?? false),
        );
    }

    /**
     * Render the filter for storage on the task that runs it.
     *
     * @return FilterPayload The filter as plain, serialisable values.
     */
    public function toPayload(): array
    {
        return [
            'document_id' => $this->documentId,
            'status' => $this->status?->value,
            'only_unscored' => $this->onlyUnscored,
        ];
    }

    /**
     * The workspace's attachments this filter selects.
     *
     * Trashed attachments and the attachments of trashed documents are left
     * out by the models' own global scopes: re-reading a file on its way to
     * being purged costs hours of CPU for text nobody will search.
     *
     * The document restriction is expressed as a subquery rather than a join
     * or `whereHas`, so that the workspace check and the document check are
     * one condition on an indexed column.
     *
     * @param Workspace $workspace The workspace whose attachments are re-read.
     *
     * @return Builder<DocumentAttachment> The matching attachments, oldest first.
     */
    public function apply(Workspace $workspace): Builder
    {
        $documents = Document::query()
            ->where('workspace_id', $workspace->id)
            ->when($this->documentId !== null, fn (Builder $query) => $query->where('id', $this->documentId))
            ->select('id');

        return DocumentAttachment::query()
            ->whereIn('document_id', $documents)
            ->when($this->status !== null, fn (Builder $query) => $query->where('ocr_status', $this->status))
            ->when($this->onlyUnscored, fn (Builder $query) => $query->whereNull('ocr_word_count'))
            ->orderBy('id');
    }
}
