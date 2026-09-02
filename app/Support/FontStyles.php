<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Vite;
use Illuminate\Support\HtmlString;

/**
 * The font block, with its asset URLs pointing where the files actually are.
 *
 * `@fonts` inlines a stylesheet the Vite plugin wrote at build time, verbatim,
 * and the `url(...)` values in it are relative to the stylesheet — which is
 * fine while it is a stylesheet, and wrong the moment it is pasted into a page.
 * The browser resolves them against the document instead: from
 * `https://example.com/archivum/login`, `./instrument-sans.woff2` becomes
 * `https://example.com/archivum/instrument-sans.woff2`, and nothing is there.
 *
 * The preload links beside them go through `asset()` and are correct, so the
 * two disagree and the fonts silently fall back to the system stack.
 *
 * Sending them through `asset()` as well makes them correct wherever the
 * installation is served from — a path prefix, a bare host, or a CDN named by
 * ASSET_URL — rather than only in the configuration the build happened to run
 * under. That matters because the published image is built once and learns
 * where it lives at runtime, which is the same reason the JavaScript route
 * URLs are rewritten in the browser.
 */
final class FontStyles
{
    /** Where the build puts font files, relative to the public directory. */
    private const ASSET_DIRECTORY = 'build/assets';

    /**
     * The font preloads and inline stylesheet, ready to print in the layout.
     *
     * @return HtmlString The markup `@fonts` would have produced, with every font URL resolved through asset().
     */
    public static function render(): HtmlString
    {
        return new HtmlString(
            self::resolveAssetUrls((string) Vite::fonts()),
        );
    }

    /**
     * Point every relative `url(...)` in a block of CSS at the built asset.
     *
     * Absolute URLs and `data:` payloads are left alone, and so is a
     * protocol-relative `//host/...`, which names a host rather than a path.
     * Everything else is a file in the build directory whatever shape the
     * plugin wrote it in — `./name.woff2` under a relative base,
     * `/build/assets/name.woff2` under an absolute one.
     *
     * @param string $css The markup or stylesheet to rewrite.
     *
     * @return string The same markup with font URLs resolved through asset().
     */
    public static function resolveAssetUrls(string $css): string
    {
        return (string) preg_replace_callback(
            '~url\((["\']?)(?!https?:|data:|//)([^"\')]+)\1\)~i',
            fn (array $match): string => sprintf(
                'url(%s%s%s)',
                $match[1],
                asset(self::ASSET_DIRECTORY . '/' . basename($match[2])),
                $match[1],
            ),
            $css,
        );
    }
}
