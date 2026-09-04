<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use App\Actions\Documents\IntakeVocabulary;
use App\Actions\Documents\SuggestDocumentMetadata;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\IntakeLabel;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IntakeReviewController extends Controller
{
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

        $documents = Document::query()
            ->where('workspace_id', $workspace->id)
            // Length rather than "not null": an empty list is a document that
            // has been read and has nothing waiting, which is not the same as
            // one nothing has read yet. See Document::recordMetadataSuggestions().
            ->whereRaw('json_length(metadata_suggestions) > 0')
            ->with('documentType')
            ->latest('updated_at')
            ->paginate(15)
            ->withQueryString();

        // One instance for the whole page: it memoises the keys each document
        // type uses, which is what keeps this off an N+1.
        $suggestions = $documents
            ->getCollection()
            ->map(fn (Document $document): array => [
                'document' => $document,
                'suggestions' => $suggest->handle($document),
            ])
            // A document whose fields were filled in between being read and
            // now has nothing left to confirm. Findings are pruned as they are
            // stored, so this is drift rather than the normal case — but the
            // sidebar counts the stored ones in SQL and would otherwise keep
            // pointing at a row that is not here. Clearing it as we pass
            // settles both, and only ever for a row that is already stale.
            ->reject(function (array $row): bool {
                if ($row['suggestions'] !== []) {
                    return false;
                }

                $row['document']->recordMetadataSuggestions([]);

                return true;
            })
            ->map(fn (array $row): array => [
                'id' => $row['document']->id,
                'title' => $row['document']->title,
                'document_type' => $row['document']->documentType?->name,
                'suggestions' => $row['suggestions'],
            ])
            ->values()
            ->all();

        return Inertia::render('documents/review', [
            'workspaceId' => $workspace->id,
            'documents' => $suggestions,
            'pagination' => [
                'prev' => $documents->previousPageUrl(),
                'next' => $documents->nextPageUrl(),
                'links' => $documents->linkCollection()->all(),
                'from' => $documents->firstItem(),
                'to' => $documents->lastItem(),
                'total' => $documents->total(),
            ],
            'duplicates' => $this->duplicates($workspace),
            'labels' => $request->user()->can('update', $workspace)
                ? $this->candidateLabels($workspace)
                : [],
        ]);
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
            ->get(['id', 'kind', 'label', 'support'])
            ->map(fn (IntakeLabel $label): array => [
                'id' => $label->id,
                'kind' => $label->kind,
                // A shipped kind has a name in the interface language; one the
                // archive invented is shown as this workspace spells it.
                'field' => $vocabulary->nameFor($label->kind, $workspace->id),
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

    /**
     * The attachments still flagged as copies of something already filed.
     *
     * Not paginated: a duplicate is dismissed in one click and the list is
     * expected to be short. If it is not, that is a signal worth seeing whole
     * rather than a page at a time.
     *
     * @param Workspace $workspace The workspace being reviewed.
     *
     * @return array<int, array{id: string, filename: string, document_id: string, document_title: string, duplicate_of: array{document_id: string, document_title: string|null}}> One entry per flagged attachment.
     */
    private function duplicates(Workspace $workspace): array
    {
        return DocumentAttachment::query()
            ->whereNotNull('duplicate_of_attachment_id')
            ->whereHas('document', fn (Builder $query) => $query->where('workspace_id', $workspace->id))
            ->with(['document', 'duplicateOf.document'])
            ->latest('created_at')
            ->get()
            ->map(fn (DocumentAttachment $attachment): array => [
                'id' => $attachment->id,
                'filename' => $attachment->filename,
                'document_id' => (string) $attachment->document_id,
                'document_title' => (string) $attachment->document?->title,
                'duplicate_of' => [
                    'document_id' => (string) $attachment->duplicateOf?->document_id,
                    'document_title' => $attachment->duplicateOf?->document?->title,
                ],
            ])
            ->values()
            ->all();
    }
}
