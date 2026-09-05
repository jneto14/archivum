<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * A signed link that survives being served under a path prefix.
 *
 * Laravel's `temporarySignedRoute()` signs the absolute URL, built — like every
 * URL here — from `APP_URL`. On an installation served at
 * `https://example.com/archivum`, that signature covers
 * `https://example.com/archivum/capture/{id}`.
 *
 * The proxy in front of such an installation strips the prefix before the
 * request arrives. It has to: `proxy_pass http://127.0.0.1:9001/` with that
 * trailing slash is what makes any route match at all. So the application is
 * asked for `/capture/{id}`, and `UrlGenerator::hasCorrectSignature()` rebuilds
 * the URL it verifies out of that request —
 * `$request->getSchemeAndHttpHost() . $request->getBaseUrl() . $request->getPathInfo()`
 * — which comes to `https://example.com/capture/{id}`, without the prefix. The
 * two strings differ, the HMAC never matches, and every signed link is refused.
 *
 * Deterministically, not intermittently: under a prefix no signed link had ever
 * worked, and on an installation served from the root there was nothing to see.
 *
 * The fix is to sign the path the application will actually be asked for, with
 * the routes validated by `signed:relative`. Both halves are needed; either one
 * alone is still a mismatch.
 */
final class SignedLink
{
    /**
     * An absolute, expiring signed link to a route.
     *
     * The root is forced to the bare host for the length of the call, and that
     * is the part worth explaining. Asking for a relative URL is not enough on
     * its own: `RouteUrlGenerator::to()` builds the absolute URL, strips the
     * scheme and host by pattern, and then strips only what
     * `$request->getBaseUrl()` reports. Behind a prefix-stripping proxy that is
     * the empty string, so the prefix stays in — and the signature covers
     * `/archivum/capture/{id}` while the request being verified is
     * `/capture/{id}`, which is the same mismatch one level down.
     *
     * Worse, it depends on where the link is built: generated from a console
     * command or a queued job there is no such request, the base URL comes from
     * `APP_URL` and does carry the prefix, and the relative URL comes out right.
     * A link would then be valid or not according to which half of the
     * application made it.
     *
     * Taking the prefix off the root before generating removes the question:
     * there is no prefix left to strip, and the same path comes back from a
     * request, from a job and from the console.
     *
     * @param string $name The route name; its middleware must include `signed:relative`.
     * @param DateTimeInterface $expiration When the link stops being accepted.
     * @param array<string, mixed> $parameters The route's parameters.
     *
     * @return string The absolute URL, signed over the path alone.
     */
    public static function temporary(string $name, DateTimeInterface $expiration, array $parameters = []): string
    {
        $root = mb_rtrim((string) config('app.url'), '/');
        $prefix = (string) config('archivum.path_prefix');

        URL::forceRootUrl($prefix === '' ? $root : Str::beforeLast($root, $prefix));

        $path = URL::temporarySignedRoute($name, $expiration, $parameters, absolute: false);

        // Nothing else forces a root, so releasing it is releasing it — there is
        // no earlier value to put back.
        URL::forceRootUrl(null);

        return $root . $path;
    }
}
