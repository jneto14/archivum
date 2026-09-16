<?php

declare(strict_types=1);

use App\Http\Middleware\ForceApplicationUrl;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PreventSearchIndexing;
use App\Http\Middleware\ResolveLocale;
use App\Http\Middleware\ResolveWorkspace;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // First, so nothing generates a URL before the root is settled.
        $middleware->prepend(ForceApplicationUrl::class);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            PreventSearchIndexing::class,
            ResolveLocale::class,
            HandleAppearance::class,
            ResolveWorkspace::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * Answer the API's 404s without naming the model that was not found.
         * Laravel's own message is "No query results for model
         * [App\Models\Document] 01a0…", which describes the code behind the
         * route rather than anything a client can act on — and this is also
         * the answer given when a row does exist and belongs to somebody else,
         * where naming it would confirm it exists.
         *
         * Both exceptions, because a render callback runs before the handler
         * converts one into the other.
         */
        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $exception, Request $request): ?JsonResponse {
            if (!$request->is('api/*')) {
                return null;
            }

            return new JsonResponse(['message' => __('api.not_found')], 404);
        });

        /*
         * A CSRF token is only as fresh as the session it lives in, so a
         * login (or any other) form left open past the session lifetime
         * submits a stale one. The 419 Laravel answers with isn't a valid
         * Inertia response — nothing else handles it, so Inertia's client
         * would show its own error modal instead of the form. Redirect back
         * with the reason flashed through the same `status` prop the login
         * page already renders.
         */
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            if (!$request->is('api/*') && $response->getStatusCode() === 419) {
                return back()->with('status', __('auth.session_expired'));
            }

            return $response;
        });
    })->create();
