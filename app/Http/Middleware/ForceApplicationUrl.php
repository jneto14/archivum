<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds every URL in a web request from APP_URL rather than from the request.
 *
 * Left to itself Laravel generates from what the request appears to say, which
 * behind a reverse proxy is what the proxy forwarded rather than what the
 * browser asked for. A proxy terminating TLS forwards plain HTTP, so redirects
 * come back as `http://`; a proxy serving the application under a path strips
 * the prefix before forwarding, so the application generates `/login` and sends
 * the browser outside the installation. APP_URL is the one place that states
 * what the outside world calls this installation.
 *
 * Middleware rather than a service provider, and this is not a detail of
 * taste. `URL::forceRootUrl()` makes the generator produce absolute URLs for
 * everything, including `wayfinder:generate`, which runs on the command line
 * during the asset build and writes every route URL into the JavaScript
 * bundle. Forced from a provider, the build bakes its own APP_URL into the
 * bundle — and the published image is built with `.env.example`, so every
 * installation would ship a front end posting to `http://localhost`.
 *
 * Nothing is lost by confining it here. Outside a request — queued mail, a
 * console command — the generator already uses `app.url` as its root, which is
 * the same answer.
 */
class ForceApplicationUrl
{
    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $url = (string) config('app.url');

        if ($url === '') {
            return $next($request);
        }

        URL::forceRootUrl($url);

        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (is_string($scheme)) {
            URL::forceScheme($scheme);
        }

        return $next($request);
    }
}
