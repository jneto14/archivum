<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use App\Actions\Documents\ApplyMetadataSuggestions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\AcceptMetadataSuggestionsRequest;
use App\Models\Document;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;

class MetadataSuggestionController extends Controller
{
    /**
     * Accept some of a document's suggested values, or none of them.
     *
     * Either way the document is done being reviewed and leaves the queue —
     * "none of these are right" is an answer, and one the user must be able to
     * give without the row coming back tomorrow.
     *
     * @param AcceptMetadataSuggestionsRequest $request The incoming request, carrying the kinds to accept.
     * @param Document $document The document being reviewed.
     * @param ApplyMetadataSuggestions $action Writes the accepted values and clears the document's findings.
     *
     * @return RedirectResponse Redirect back to the review queue.
     *
     * @throws AuthorizationException If the current user cannot update $document.
     */
    public function store(
        AcceptMetadataSuggestionsRequest $request,
        Document $document,
        ApplyMetadataSuggestions $action,
    ): RedirectResponse {
        $this->authorize('update', $document);

        $action->handle($document, $request->kinds());

        return back();
    }
}
