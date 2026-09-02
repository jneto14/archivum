<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps an installation out of search results.
 *
 * Archivum is an internal application. Every screen but the login sits behind
 * authentication, so there is nothing to rank and nothing to gain — while a
 * login page in a search index quietly announces that a particular
 * organisation keeps an archive at a particular address. That serves nobody.
 *
 * Sent as a header rather than only as a `<meta>` tag because a meta tag only
 * covers HTML. Attachment downloads, previews and exported CSVs are served by
 * routes too, and those are the responses whose contents would matter most.
 *
 * This is not access control. `robots.txt` and `X-Robots-Tag` are instructions
 * that well-behaved crawlers follow and anything else ignores; they keep an
 * installation out of Google, not out of reach.
 */
class PreventSearchIndexing
{
    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
