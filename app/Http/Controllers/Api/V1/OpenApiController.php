<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\OpenApiSpec;
use Illuminate\Http\JsonResponse;

/**
 * Serves the API's own description.
 *
 * Built from the route table on every request. There is no file to keep in
 * step, no step anybody can forget, and nothing to deploy: what a client gets
 * describes the application that is answering it.
 */
class OpenApiController extends Controller
{
    /**
     * Return the OpenAPI document, pointed at this installation.
     *
     * Deliberately outside `auth:sanctum`. It describes how to authenticate,
     * so requiring authentication to read it is a bootstrapping problem, and
     * it is already public in the repository — there is nothing here a token
     * would be protecting. Still throttled, being an unauthenticated route.
     *
     * The document carries `{origin}` as a server variable, so that it says
     * something true for any installation. Served from one, there is no such
     * ambiguity, so the variable is resolved here — through `url()`, so an
     * installation behind a path prefix gets the prefix too.
     *
     * @param OpenApiSpec $spec Builds the document from the route table.
     *
     * @return JsonResponse The spec, with its server URL resolved.
     */
    public function show(OpenApiSpec $spec): JsonResponse
    {
        $document = $spec->build();

        $document['servers'] = [['url' => url('/api/v1')]];

        return new JsonResponse($document, options: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
