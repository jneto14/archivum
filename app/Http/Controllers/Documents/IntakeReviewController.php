<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use App\Actions\Documents\SuggestDocumentMetadata;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
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
     * @param Workspace $workspace The workspace being reviewed.
     * @param SuggestDocumentMetadata $suggest Resolves each document's stored findings against the fields it still has empty.
     *
     * @return Response The rendered review page.
     *
     * @throws AuthorizationException If the current user isn't a member of $workspace.
     */
    public function index(Workspace $workspace, SuggestDocumentMetadata $suggest): Response
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
                'id' => $document->id,
                'title' => $document->title,
                'document_type' => $document->documentType?->name,
                'suggestions' => $suggest->handle($document),
            ])
            // A document whose fields were all filled in by hand between
            // extraction and now has nothing left to confirm. It is cleaned up
            // on its next edit; until then it must not be listed with an empty
            // body.
            ->filter(fn (array $row): bool => $row['suggestions'] !== [])
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
        ]);
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
