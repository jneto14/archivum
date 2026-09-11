<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use App\Actions\Documents\AnswerReviewInBulk;
use App\Actions\Documents\IntakeVocabulary;
use App\Actions\Documents\SuggestDocumentMetadata;
use App\Enums\ReviewFilter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\BulkReviewRequest;
use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\IntakeLabel;
use App\Models\Workspace;
use App\Support\ReviewQueue;
use App\Support\TableSort;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class IntakeReviewController extends Controller
{
    /**
     * How much is waiting on a document, for the sort control: its stored
     * findings plus the attachments under it still to be answered for.
     *
     * Correlated subqueries rather than `withCount`, because the sort has to
     * read the same number the row shows and `TableSort` orders by an
     * expression rather than by a select alias.
     */
    private const WAITING_COUNT = <<<'SQL'
        (
            coalesce(json_length(documents.metadata_suggestions), 0)
            + (
                select count(*) from document_attachments a
                where a.document_id = documents.id and a.deleted_at is null
                  and a.ocr_reviewed_at is null
                  and (
                      a.ocr_status = 'poorly_read'
                      or (a.ocr_status = 'completed' and a.ocr_text is not null and a.ocr_text <> ''
                          and a.ocr_confident_word_count < a.ocr_word_count)
                  )
            )
            + (
                select count(*) from document_attachments a
                where a.document_id = documents.id and a.deleted_at is null
                  and a.duplicate_of_attachment_id is not null
            )
        )
        SQL;

    /**
     * Everything the application worked out about recently filed documents and
     * is waiting on somebody to confirm.
     *
     * This page exists because the alternative is opening every document.
     * Extraction finishes minutes after a document is registered, long after
     * whoever registered it has moved on to the next one, so anything it found
     * has to be collected somewhere rather than waiting on each document's own
     * page for a visit that is not coming.
     *
     * @param Request $request The incoming request, used to resolve the acting user.
     * @param Workspace $workspace The workspace being reviewed.
     * @param SuggestDocumentMetadata $suggest Resolves each document's stored findings against the fields it still has empty.
     *
     * @return Response The rendered review page.
     *
     * @throws AuthorizationException If the current user isn't a member of $workspace.
     */
    public function index(Request $request, Workspace $workspace, SuggestDocumentMetadata $suggest): Response
    {
        $this->authorize('viewAny', [Document::class, $workspace]);

        $filter = ReviewFilter::fromRequestValue($request->string('filter')->toString() ?: null);
        $queue = new ReviewQueue($filter);

        $sort = TableSort::fromRequest($request, [
            'title' => 'documents.title',
            'updated_at' => 'documents.updated_at',
            'waiting' => DB::raw(self::WAITING_COUNT),
        ], 'updated_at', 'desc');

        // Both attachment kinds come back in one eager load and are sorted
        // into their two lists in PHP. Asking for them as two constrained
        // relations would mean declaring two relations on the model that
        // differ only by a where, and asking per document would be the N+1
        // this page most invites.
        $documents = $queue->documents($workspace)
            ->with('documentType')
            ->with(['attachmentsAwaitingReview' => fn ($query) => $query
                ->with('duplicateOf.document:id,title')])
            ->tap(fn (Builder $query) => $sort->apply($query, 'documents.id'))
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('documents/review', [
            'workspaceId' => $workspace->id,
            'filter' => $filter->value,
            'sort' => $sort->toArray(),
            'documents' => $this->rows($documents->getCollection(), $suggest),
            'counts' => $this->counts($workspace),
            'pagination' => [
                'prev' => $documents->previousPageUrl(),
                'next' => $documents->nextPageUrl(),
                'links' => $documents->linkCollection()->all(),
                'from' => $documents->firstItem(),
                'to' => $documents->lastItem(),
                'total' => $documents->total(),
            ],
            'labels' => $request->user()->can('update', $workspace)
                ? $this->candidateLabels($workspace)
                : [],
        ]);
    }

    /**
     * Answer for many documents at once.
     *
     * @param BulkReviewRequest $request The incoming request, carrying the action, the filter it was made against and the documents picked.
     * @param Workspace $workspace The workspace being reviewed.
     * @param AnswerReviewInBulk $action Applies the answer to the selection.
     *
     * @return RedirectResponse Redirect back to the queue.
     *
     * @throws AuthorizationException If the current user cannot update documents in $workspace.
     */
    public function store(BulkReviewRequest $request, Workspace $workspace, AnswerReviewInBulk $action): RedirectResponse
    {
        $this->authorize('create', [Document::class, $workspace]);

        $answered = $action->handle(
            $workspace,
            $request->action(),
            new ReviewQueue($request->filter()),
            $request->documentIds(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('document.review.' . $request->action()->value, ['count' => $answered]),
        ]);

        return back();
    }

    /**
     * Render one row per document, carrying everything waiting on it.
     *
     * @param Collection<int, Document> $documents The page of documents.
     * @param SuggestDocumentMetadata $suggest Resolves each document's stored findings against the fields it still has empty.
     *
     * @return array<int, array<string, mixed>> One entry per document.
     */
    private function rows(Collection $documents, SuggestDocumentMetadata $suggest): array
    {
        return $documents
            ->map(function (Document $document) use ($suggest): array {
                $readings = $document->attachmentsAwaitingReview
                    ->filter(fn (DocumentAttachment $attachment): bool => $attachment->isAwaitingReadingReview());
                $duplicates = $document->attachmentsAwaitingReview
                    ->filter(fn (DocumentAttachment $attachment): bool => $attachment->duplicate_of_attachment_id !== null);
                $suggestions = $suggest->handle($document);

                // Findings are pruned as they are stored, so a document whose
                // fields were filled in between being read and now is drift
                // rather than the normal case. Clearing it as we pass settles
                // the row and the sidebar badge together: the badge counts the
                // stored findings in SQL and would otherwise go on pointing at
                // a row this page does not show.
                if ($suggestions === [] && ($document->metadata_suggestions ?? []) !== []) {
                    $document->recordMetadataSuggestions([]);
                }

                return [
                    'id' => $document->id,
                    'title' => $document->title,
                    'document_type' => $document->documentType?->name,
                    'suggestions' => $suggestions,
                    'readings' => $readings
                        ->map(fn (DocumentAttachment $attachment): array => [
                            'id' => $attachment->id,
                            'filename' => $attachment->filename,
                            'document_id' => (string) $attachment->document_id,
                            'document_title' => $document->title,
                            'text' => $attachment->ocr_text,
                            'word_count' => $attachment->ocr_word_count,
                            'unread_word_count' => $attachment->ocr_word_count === null
                                ? null
                                : $attachment->ocr_word_count - (int) $attachment->ocr_confident_word_count,
                        ])
                        ->values()
                        ->all(),
                    'duplicates' => $duplicates
                        ->map(fn (DocumentAttachment $attachment): array => [
                            'id' => $attachment->id,
                            'filename' => $attachment->filename,
                            'duplicate_of' => [
                                'document_id' => (string) $attachment->duplicateOf?->document_id,
                                'document_title' => $attachment->duplicateOf?->document?->title,
                            ],
                        ])
                        ->values()
                        ->all(),
                    'waiting' => count($suggestions) + $readings->count() + $duplicates->count(),
                ];
            })
            // Nothing left to answer for, once the drift above is settled.
            ->reject(fn (array $row): bool => $row['waiting'] === 0)
            ->values()
            ->all();
    }

    /**
     * How many documents each filter would show, for the filter control.
     *
     * @param Workspace $workspace The workspace being reviewed.
     *
     * @return array<string, int> The count per filter value.
     */
    private function counts(Workspace $workspace): array
    {
        $counts = [];

        foreach (ReviewFilter::cases() as $filter) {
            $counts[$filter->value] = (new ReviewQueue($filter))->documents($workspace)->count();
        }

        return $counts;
    }

    /**
     * The phrases the archive taught itself and is waiting to be told about.
     *
     * Here rather than on the workspace settings page, where they were first
     * put. Settings carries no badge and nobody opens it looking for work, so a
     * candidate could sit there for months — and a question nobody is shown is
     * the same as one that was never asked. This queue is already where the
     * application collects what it worked out and cannot confirm on its own.
     *
     * Admins only: answering one changes how every document in the workspace is
     * read, which is not a member's decision to make. The sidebar count is
     * scoped the same way, so nobody is badged towards a section they will not
     * be shown.
     *
     * @param Workspace $workspace The workspace being reviewed.
     *
     * @return array<int, array{id: string, kind: string, field: string, label: string, support: int, documents: array<int, array{id: string, title: string}>}> One entry per candidate, best evidenced first.
     */
    private function candidateLabels(Workspace $workspace): array
    {
        $vocabulary = app(IntakeVocabulary::class);

        return IntakeLabel::query()
            ->where('workspace_id', $workspace->id)
            ->offered()
            ->orderByDesc('support')
            ->orderBy('label')
            // A few of the documents that taught it, not the count alone: a
            // number asks to be trusted, where three titles let an admin open
            // one and see the phrase in the place it was read from.
            ->with(['documents' => fn ($query) => $query->select('documents.id', 'documents.title')->limit(3)])
            ->get(['id', 'kind', 'field', 'label', 'support'])
            ->map(fn (IntakeLabel $label): array => [
                'id' => $label->id,
                'kind' => $label->kind,
                // A shipped kind has a name in the interface language; one the
                // archive invented is shown as this workspace spells it.
                'field' => $vocabulary->nameFor($label->kind, $workspace->id, $label->field),
                'label' => $label->label,
                'support' => $label->support,
                'documents' => $label->documents
                    ->map(fn (Document $document): array => [
                        'id' => $document->id,
                        'title' => $document->title,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }
}
