<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Documents\AnswerReviewInBulk;
use App\Actions\Documents\SuggestDocumentMetadata;
use App\Enums\ReviewFilter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\BulkReviewRequest;
use App\Http\Resources\Api\V1\DocumentResource;
use App\Models\Document;
use App\Models\Workspace;
use App\Support\PageSize;
use App\Support\ReviewQueue;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * What the archive worked out on its own and cannot confirm without being told.
 */
class IntakeReviewController extends Controller
{
    /**
     * List the documents waiting on an answer.
     *
     * The counts for every filter come back alongside, because they are what a
     * client needs to decide which queue is worth working and they cost one
     * query each either way.
     *
     * @param Request $request The incoming request, read for the `filter` and the page size.
     * @param Workspace $workspace The workspace being reviewed.
     *
     * @return ResourceCollection A page of documents awaiting review, with per-filter counts in meta.
     *
     * @throws AuthorizationException If the token's user isn't a member of $workspace.
     */
    public function index(Request $request, Workspace $workspace): ResourceCollection
    {
        $this->authorize('viewAny', [Document::class, $workspace]);

        $filter = ReviewFilter::fromRequestValue($request->string('filter')->toString() ?: null);

        $documents = (new ReviewQueue($filter))
            ->documents($workspace)
            ->with(['documentType', 'attachmentsAwaitingReview.duplicateOf'])
            ->latest('documents.updated_at')
            ->orderByDesc('documents.id')
            ->paginate(PageSize::fromRequest($request))
            ->withQueryString();

        $counts = [];

        foreach (ReviewFilter::cases() as $case) {
            $counts[$case->value] = (new ReviewQueue($case))->documents($workspace)->count();
        }

        return DocumentResource::collection($documents)->additional([
            'meta' => ['filter' => $filter->value, 'counts' => $counts],
        ]);
    }

    /**
     * Suggest metadata for one document, from what its scans say.
     *
     * A read rather than a write: nothing is applied until a client asks for
     * it by kind, which is what the accept endpoint on the document is for.
     *
     * @param Document $document The document to read suggestions for.
     * @param SuggestDocumentMetadata $suggest Works the suggestions out of the document's extracted text.
     *
     * @return JsonResponse The suggested values, keyed by kind.
     *
     * @throws AuthorizationException If the token's user cannot view $document.
     */
    public function suggestions(Document $document, SuggestDocumentMetadata $suggest): JsonResponse
    {
        $this->authorize('view', $document);

        return new JsonResponse(['data' => $suggest->handle($document)]);
    }

    /**
     * Answer for many documents at once.
     *
     * The filter travels with the answer rather than being inferred, so
     * "accept everything" means everything in the queue the client was looking
     * at — not everything that happens to qualify by the time the request
     * lands (ARC-127).
     *
     * @param BulkReviewRequest $request The incoming request carrying the action, the filter it was made against, and the documents picked.
     * @param Workspace $workspace The workspace being reviewed.
     * @param AnswerReviewInBulk $action Applies the answer to the selection.
     *
     * @return JsonResponse How many documents were answered.
     *
     * @throws AuthorizationException If the token's user cannot create documents in $workspace.
     */
    public function store(BulkReviewRequest $request, Workspace $workspace, AnswerReviewInBulk $action): JsonResponse
    {
        $this->authorize('create', [Document::class, $workspace]);

        $answered = $action->handle(
            $workspace,
            $request->action(),
            new ReviewQueue($request->filter()),
            $request->documentIds(),
        );

        return new JsonResponse(['data' => ['answered' => $answered]]);
    }
}
