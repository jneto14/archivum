<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Console\Commands\GenerateOpenApiSpec;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the API's own description.
 *
 * The committed spec carries `{origin}` as a server variable, because it lives
 * in a repository every installation deploys from its own host and baking a
 * URL into it would write whichever machine ran the generator into everybody's
 * copy. Served from a running installation there is no such ambiguity, so this
 * fills that variable in with the real one — which is the thing a client
 * generator or an HTTP client wants and cannot work out for itself.
 */
class OpenApiController extends Controller
{
    /**
     * Return the OpenAPI document, pointed at this installation.
     *
     * Deliberately outside `auth:sanctum`. It describes how to authenticate,
     * so requiring authentication to read it is a bootstrapping problem, and
     * it is already public in the repository — there is nothing here that a
     * token would be protecting. It is still throttled, being a file read on
     * an unauthenticated route.
     *
     * @return JsonResponse The spec, with its server URL resolved.
     *
     * @throws NotFoundHttpException If the spec is missing from the deployment.
     */
    public function show(): JsonResponse
    {
        $path = base_path(GenerateOpenApiSpec::PATH);

        abort_unless(File::exists($path), 404);

        /** @var array<string, mixed> $spec */
        $spec = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);

        // `url()` rather than the configured value directly, so an
        // installation served under a path prefix gets the prefix too.
        $spec['servers'] = [['url' => url('/api/v1')]];

        return new JsonResponse($spec, options: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
