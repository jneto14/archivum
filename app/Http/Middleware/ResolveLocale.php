<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class ResolveLocale
{
    /**
     * Resolve the active locale for this request: the authenticated user's
     * preference, falling back to the browser's Accept-Language header,
     * falling back to the application's configured default locale.
     *
     * @param  Request  $request  The incoming request.
     * @param  Closure(Request): Response  $next  The next middleware/handler in the pipeline.
     * @return Response The response produced by the rest of the pipeline.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $supported = array_map('strval', array_keys(config('archivum.locales')));
        $user = $request->user();

        $locale = ($user instanceof User ? $user->locale : null)
            ?? $request->getPreferredLanguage($supported)
            ?? config('app.locale');

        App::setLocale($locale);

        return $next($request);
    }
}
