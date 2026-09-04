import { normalizePrefix, pathPrefix } from '@/lib/path-prefix';
import { serviceWorker } from '@/routes/pwa';

/**
 * Registers the service worker that makes this installation installable.
 *
 * The two values here are the whole reason this is not a one-liner in app.tsx.
 * A worker's URL decides how much of the site it may claim — the browser
 * refuses any scope above the directory the script came from — and both the URL
 * and that scope have to follow the path this installation is served under.
 * Neither is knowable at build time: the published image is built once and
 * learns where it lives from the page it is served in. See lib/path-prefix.ts.
 */

/**
 * The scope a worker registered under `prefix` must claim: the root of the
 * installation and everything below it.
 *
 * Always ends in a slash. Without it the browser treats the value as a prefix
 * match on the URL string rather than a directory, so `/archivum` would also
 * claim `/archivumsomething` — a different installation on the same host.
 *
 * @param prefix The path this installation is served under; empty for a host of its own.
 *
 * @return The scope to register with, '/' when there is no prefix.
 */
export function serviceWorkerScope(prefix: string = pathPrefix()): string {
    return `${normalizePrefix(prefix)}/`;
}

/**
 * Register the worker, once the page it was asked from has finished loading.
 *
 * Deliberately not restricted to production builds. The worker caches nothing
 * the Vite dev server serves — those assets are on another origin, and it only
 * ever caches URLs under the build directory — so leaving it registered costs
 * nothing, and registering it everywhere means a browser that installed it once
 * keeps being handed the current script instead of holding the one it happened
 * to get from a build.
 *
 * @return Nothing; a browser without service workers is left alone.
 */
export function registerServiceWorker(): void {
    if (!('serviceWorker' in navigator)) {
        return;
    }

    // After load: registration competes with the page's own requests for the
    // connection otherwise, and nothing on the first visit depends on it.
    window.addEventListener('load', () => {
        navigator.serviceWorker
            .register(serviceWorker.url(), { scope: serviceWorkerScope() })
            .catch((error: unknown) => {
                console.warn('Service worker registration failed', error);
            });
    });
}
