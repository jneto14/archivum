/**
 * Archivum's service worker.
 *
 * Rendered by App\Http\Controllers\PwaController, not written by hand into
 * public/ — see that class for why it is a route.
 *
 * It is deliberately small, and deliberately caches almost nothing. An archive
 * is not public, so no page and no attachment may end up in a cache that
 * outlives the session that fetched it. What is safe to keep is the asset
 * build: every file under the build directory carries a content hash in its
 * name, so a cached copy can never turn out to be the wrong version of itself.
 *
 * Everything else falls through to the browser untouched, including the XHR
 * that Inertia navigations actually are.
 */

{{-- Unescaped slashes on the two URLs, so the script a browser (or a developer)
     reads says `https://host/build/` rather than `https:\/\/host\/build\/`. --}}
const VERSION = @json($version, JSON_UNESCAPED_SLASHES);
const BUILD_BASE = @json($buildBase, JSON_UNESCAPED_SLASHES);
const OFFLINE_DOCUMENT = @json($offlineDocument);

/**
 * Cache key for the offline page. Not a real route — nothing is ever fetched
 * from it — just a stable same-origin URL to file the document under.
 */
const OFFLINE_URL = new URL('?offline', self.location.href).href;

const ASSET_CACHE = `archivum-assets-${VERSION}`;
const SHELL_CACHE = `archivum-shell-${VERSION}`;

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(SHELL_CACHE).then((cache) =>
            cache.put(
                OFFLINE_URL,
                new Response(OFFLINE_DOCUMENT, {
                    headers: { 'Content-Type': 'text/html; charset=utf-8' },
                }),
            ),
        ),
    );

    // Take over immediately rather than waiting for every tab to close. Safe
    // here because this worker never serves a cached page: a tab from the
    // previous build keeps talking to the network, and Inertia's own asset
    // versioning turns its next navigation into a full reload.
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            const keys = await caches.keys();

            await Promise.all(
                keys
                    .filter(
                        (key) =>
                            key.startsWith('archivum-') &&
                            key !== ASSET_CACHE &&
                            key !== SHELL_CACHE,
                    )
                    .map((key) => caches.delete(key)),
            );

            await self.clients.claim();
        })(),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') {
        return;
    }

    // An ASSET_URL pointing at a CDN, a font host, anything else the page
    // reaches for: not ours to answer.
    if (new URL(request.url).origin !== self.location.origin) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(networkFirst(request));

        return;
    }

    if (request.url.startsWith(BUILD_BASE)) {
        event.respondWith(cacheFirst(request));
    }
});

/**
 * Pages always come from the network, and are never written to a cache. The
 * only thing this adds is what happens when the network is gone: the offline
 * document instead of the browser's own error page, which in a standalone
 * window is a blank screen with no way back.
 */
async function networkFirst(request) {
    try {
        return await fetch(request);
    } catch {
        const offline = await caches.match(OFFLINE_URL);

        return offline ?? Response.error();
    }
}

/**
 * Cache-first, because the name of a built asset already states its contents.
 * A file left over from the previous build is not stale, only unused, and the
 * activate handler above has already dropped the cache it lived in.
 */
async function cacheFirst(request) {
    const cache = await caches.open(ASSET_CACHE);
    const cached = await cache.match(request);

    if (cached) {
        return cached;
    }

    const response = await fetch(request);

    if (response.ok) {
        cache.put(request, response.clone());
    }

    return response;
}
