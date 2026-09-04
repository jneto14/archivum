import { describe, expect, it } from 'vitest';
import { serviceWorkerScope } from '@/lib/service-worker';

describe('serviceWorkerScope', () => {
    it('claims the whole installation when it has a host of its own', () => {
        expect(serviceWorkerScope('')).toBe('/');
    });

    it('claims only the path an installation is served under', () => {
        expect(serviceWorkerScope('/archivum')).toBe('/archivum/');
        expect(serviceWorkerScope('/apps/archivum')).toBe('/apps/archivum/');
    });

    it('accepts the prefix however APP_URL happened to write it', () => {
        expect(serviceWorkerScope('archivum')).toBe('/archivum/');
        expect(serviceWorkerScope('/archivum/')).toBe('/archivum/');
    });

    // Without the trailing slash the browser matches the scope as a string
    // prefix rather than a directory, and one installation would claim the
    // pages of another sharing the host.
    it('always ends in a slash so a sibling installation is not claimed', () => {
        expect(serviceWorkerScope('/archiv')).toBe('/archiv/');
        expect(serviceWorkerScope('/archivum-old')).not.toBe(
            serviceWorkerScope('/archivum'),
        );
    });
});
