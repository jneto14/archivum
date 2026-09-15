<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Documents\ApplyMetadataSuggestions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\AcceptMetadataSuggestionsRequest;
use App\Http\Resources\Api\V1\DocumentResource;
use App\Models\Document;
use Illuminate\Auth\Access\AuthorizationException;

class MetadataSuggestionController extends Controller
{
    /**
     * Accept some of the metadata the archive suggested for a document.
     *
     * By kind rather than wholesale, because the point of a suggestion is that
     * somebody looked at it: a client taking the date and leaving the
     * counterparty is the ordinary case, not an edge one.
     *
     * @param AcceptMetadataSuggestionsRequest $request The incoming request naming the kinds being accepted.
     * @param Document $document The document the suggestions are applied to.
     * @param ApplyMetadataSuggestions $action Writes the accepted values onto the document.
     *
     * @return DocumentResource The document, with the accepted values on it.
     *
     * @throws AuthorizationException If the token's user cannot update $document.
     */
    public function store(
        AcceptMetadataSuggestionsRequest $request,
        Document $document,
        ApplyMetadataSuggestions $action,
    ): DocumentResource {
        $this->authorize('update', $document);

        $action->handle($document, $request->kinds());

        return new DocumentResource(
            $document->refresh()->load(['documentType', 'tags', 'creator']),
        );
    }
}
