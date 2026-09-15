<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\OpenApiSpec;
use Illuminate\Http\JsonResponse;

/**
 * Serves the API's own description.
 *
 * Built from the route table on every request rather than read off the disk,
 * so what a client gets describes the application that is running. There is no
 * step anybody can forget and no file that can go stale — and no reason for a
 * deployment to carry `docs/openapi.json` at all.
 *
 * The committed snapshot exists for review, so a change to the contract shows
 * up in a diff. Nothing here reads it.
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
     * The committed snapshot carries `{origin}` as a server variable, because
     * it lives in a repository every installation deploys from its own host
     * and baking a URL into it would write whichever machine ran the command
     * into everybody's copy. Served from a running installation there is no
     * such ambiguity, so the variable is resolved — through `url()`, so an
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
