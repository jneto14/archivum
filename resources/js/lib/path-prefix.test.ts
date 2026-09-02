import { beforeEach, describe, expect, it } from 'vitest';
import {
    applyPathPrefix,
    normalizePrefix,
    pathPrefix,
} from '@/lib/path-prefix';

/**
 * Stands in for a generated Wayfinder module, in the shape the generator
 * actually emits: a function carrying its own `definition`, a `url()` that
 * reads the definition back **at call time**, and the other verbs built on top
 * of `url()`. That last property is the whole reason this works — rewriting the
 * definition moves every shape at once.
 */
function routeModule() {
    const login = (() => ({ url: login.url(), method: 'get' })) as {
        (): { url: string; method: string };
        definition: { methods: string[]; url: string };
        url: () => string;
        form: { action: string };
        get: () => { url: string };
    };

    login.definition = { methods: ['get', 'head'], url: '/login' };
    login.url = () => login.definition.url;
    login.get = () => ({ url: login.url() });

    Object.defineProperty(login, 'form', {
        get: () => ({ action: login.url() }),
    });

    return login;
}

beforeEach(() => {
    document.head.innerHTML = '';
});

describe('normalizePrefix', () => {
    it.each([
        ['/archivum', '/archivum'],
        ['archivum', '/archivum'],
        ['/archivum/', '/archivum'],
        ['  /archivum  ', '/archivum'],
        ['/nested/deeper/', '/nested/deeper'],
        ['', ''],
        ['/', ''],
    ])('reads %o as %o', (given, expected) => {
        expect(normalizePrefix(given)).toBe(expected);
    });
});

describe('pathPrefix', () => {
    it('is empty on an installation served from its own hostname', () => {
        document.head.innerHTML = '<meta name="app-path-prefix" content="">';

        expect(pathPrefix()).toBe('');
    });

    it('is empty when the page says nothing at all', () => {
        expect(pathPrefix()).toBe('');
    });

    it('reads the prefix the server declared', () => {
        document.head.innerHTML =
            '<meta name="app-path-prefix" content="/archivum">';

        expect(pathPrefix()).toBe('/archivum');
    });
});

describe('applyPathPrefix', () => {
    /**
     * The assertions go through `url()`, `get()` and `form` rather than reading
     * the definition back, because those are what the application actually
     * hands to a Link, to a Form and to the router. Reading the field we just
     * wrote would prove only that assignment works.
     */
    it('moves every shape a route is used in', () => {
        const login = routeModule();

        applyPathPrefix({ './routes/index.ts': { login } }, '/archivum');

        expect(login.url()).toBe('/archivum/login');
        expect(login.get().url).toBe('/archivum/login');
        expect(login.form.action).toBe('/archivum/login');
        expect(login().url).toBe('/archivum/login');
    });

    it('leaves an installation without a prefix untouched', () => {
        const login = routeModule();

        applyPathPrefix({ './routes/index.ts': { login } }, '');

        expect(login.url()).toBe('/login');
    });

    /**
     * Wayfinder re-exports the same function objects from several modules — a
     * parent importing a child, `Object.assign(index, index)` — so the walk
     * reaches the same definition more than once. Prefixing twice would produce
     * `/archivum/archivum/login`, which is the kind of thing that works in a
     * unit test written the other way round and breaks in the browser.
     */
    it('does not prefix a route reached from two modules twice', () => {
        const login = routeModule();

        applyPathPrefix(
            {
                './routes/index.ts': { login },
                './routes/auth.ts': { login, nested: { login } },
            },
            '/archivum',
        );

        expect(login.url()).toBe('/archivum/login');
    });

    it('does not prefix again when applied a second time', () => {
        const login = routeModule();
        const modules = { './routes/index.ts': { login } };

        applyPathPrefix(modules, '/archivum');
        applyPathPrefix(modules, '/archivum');

        expect(login.url()).toBe('/archivum/login');
    });

    /**
     * Wayfinder takes the path of APP_URL into account when it generates, so an
     * image someone built for their own installation already carries the
     * prefix. Adding it again would produce `/archivum/archivum/login`.
     */
    it('leaves a build that already carries the prefix alone', () => {
        const login = routeModule();
        login.definition.url = '/archivum/login';

        applyPathPrefix({ './routes/index.ts': { login } }, '/archivum');

        expect(login.url()).toBe('/archivum/login');
    });

    it('still prefixes a route that merely starts with the same letters', () => {
        const login = routeModule();
        login.definition.url = '/archivumsomething';

        applyPathPrefix({ './routes/index.ts': { login } }, '/archivum');

        expect(login.url()).toBe('/archivum/archivumsomething');
    });

    it('reaches routes nested in a default export group', () => {
        const login = routeModule();

        applyPathPrefix(
            {
                './routes/workspaces/index.ts': {
                    default: { auth: { login } },
                },
            },
            '/archivum',
        );

        expect(login.url()).toBe('/archivum/login');
    });

    it('ignores a module carrying no route definitions', () => {
        expect(() =>
            applyPathPrefix(
                { './routes/empty.ts': { queryParams: () => '' } },
                '/archivum',
            ),
        ).not.toThrow();
    });
});
