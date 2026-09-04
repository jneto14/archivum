import { afterEach, expect, it, vi } from 'vitest';
import { randomId } from '@/lib/utils';

/**
 * `randomId` exists because `crypto.randomUUID()` is restricted to secure
 * contexts, and this application is routinely served over plain HTTP on a LAN.
 * Attaching a file to a document threw `crypto.randomUUID is not a function`
 * and took the page down with it.
 *
 * The environment is therefore the test: under jsdom, which provides the whole
 * of Web Crypto, any implementation passes — including the broken one. So each
 * case takes away what an insecure context takes away.
 */
afterEach(() => vi.unstubAllGlobals());

it('generates distinct ids', () => {
    const ids = new Set(Array.from({ length: 500 }, () => randomId()));

    expect(ids.size).toBe(500);
});

it('works where crypto.randomUUID does not exist', () => {
    // Exactly what a browser exposes over http://192.168.x.x: getRandomValues,
    // and nothing that a secure context gates.
    vi.stubGlobal('crypto', {
        getRandomValues: crypto.getRandomValues.bind(crypto),
    });

    expect(randomId()).toMatch(/^[0-9a-f]{32}$/);
});

it('works where there is no Web Crypto at all', () => {
    vi.stubGlobal('crypto', undefined);

    expect(randomId()).toMatch(/^[0-9a-f]{32}$/);
});
