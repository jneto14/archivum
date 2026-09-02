/**
 * Teaches the compiled bundle which path the installation is served under.
 *
 * Wayfinder writes route URLs into the JavaScript at build time as
 * root-relative literals — `/login`, never `/archivum/login`. The published
 * image carries one build, so it cannot know a prefix the operator chose
 * afterwards, and none of the layers below offer a way to add one later:
 * Wayfinder's own runtime has `setUrlDefaults` for route *parameters* only,
 * Inertia resolves every href with `new URL(href, window.location)` where a
 * root-relative path ignores the current directory, and `<base href>` does not
 * apply to root-relative URLs either.
 *
 * What Wayfinder does give is a single seam. Every generated route reads its
 * path back out of its own definition at call time:
 *
 *     login.url = (options) => login.definition.url + queryParams(options)
 *
 * and every other shape — `.get()`, `.post()`, `.form()`, `.head()` — goes
 * through that same `url()`. So rewriting `definition.url` once, before
 * anything renders, moves every URL in the application at the same moment:
 * the `href` on a `Link`, the `action` on a `Form`, what `router.visit()` is
 * handed, what `useForm().post()` submits. One mechanism rather than a patch
 * per consumer, and no way for a new call site to miss it.
 */

/** Definitions already rewritten, so a module reached twice is not prefixed twice. */
const rewritten = new WeakSet<object>();

/**
 * The path this installation is served under, or '' when it is served from the
 * root of its own hostname.
 *
 * Read from a meta tag rather than from Inertia's page props because it has to
 * be known before the first route module is used, and a meta tag is in the
 * document from the moment it is parsed.
 */
export function pathPrefix(): string {
    if (typeof document === 'undefined') {
        return '';
    }

    const declared = document
        .querySelector('meta[name="app-path-prefix"]')
        ?.getAttribute('content')
        ?.trim();

    return normalizePrefix(declared ?? '');
}

/** '/archivum/' and 'archivum' both mean the same thing; '' and '/' mean none. */
export function normalizePrefix(value: string): string {
    const trimmed = value.trim().replace(/\/+$/, '');

    if (trimmed === '') {
        return '';
    }

    return trimmed.startsWith('/') ? trimmed : `/${trimmed}`;
}

/**
 * Rewrite every route definition in the given modules to sit under `prefix`.
 *
 * @param modules The eagerly imported Wayfinder route and action modules.
 * @param prefix  The path prefix; an empty one leaves everything untouched.
 */
export function applyPathPrefix(
    modules: Record<string, unknown>,
    prefix: string = pathPrefix(),
): void {
    if (prefix === '') {
        return;
    }

    const visited = new WeakSet<object>();

    for (const module of Object.values(modules)) {
        rewrite(module, prefix, visited);
    }
}

function rewrite(
    value: unknown,
    prefix: string,
    visited: WeakSet<object>,
): void {
    if (
        value === null ||
        (typeof value !== 'object' && typeof value !== 'function')
    ) {
        return;
    }

    const node = value as Record<string, unknown>;

    // Wayfinder re-exports the same function objects from several modules
    // (`Object.assign(index, index)`, a parent importing a child), so without
    // this the same definition is reached more than once.
    if (visited.has(node)) {
        return;
    }

    visited.add(node);

    const definition = node.definition;

    if (isDefinition(definition) && !rewritten.has(definition)) {
        rewritten.add(definition);

        if (!isAlreadyUnder(definition.url, prefix)) {
            definition.url = prefix + definition.url;
        }
    }

    for (const key of Object.keys(node)) {
        rewrite(node[key], prefix, visited);
    }
}

/**
 * Whether the build already wrote this URL under the prefix.
 *
 * Wayfinder takes the *path* of APP_URL into account when it generates, so a
 * build run against `https://example.com/archivum` emits `/archivum/login`
 * rather than `/login`. The published image is built without a path and needs
 * the prefix added here; an image someone built for their own installation
 * already has it, and adding it again would produce `/archivum/archivum/login`.
 *
 * The boundary check matters: `/archivumsomething` is a different route, not
 * this one already prefixed.
 */
function isAlreadyUnder(url: string, prefix: string): boolean {
    return url === prefix || url.startsWith(`${prefix}/`);
}

function isDefinition(value: unknown): value is { url: string } {
    return (
        typeof value === 'object' &&
        value !== null &&
        typeof (value as { url?: unknown }).url === 'string' &&
        (value as { url: string }).url.startsWith('/')
    );
}
