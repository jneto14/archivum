<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Vite;

/**
 * What makes this installation installable: the web app manifest, and the
 * service worker behind it.
 *
 * Both are rendered rather than served as files from `public/`, for the same
 * reason the JavaScript route URLs are rewritten in the browser — the published
 * image is built once and only learns at runtime whether it is served from a
 * host of its own or from a path on somebody else's. A static manifest has to
 * write `start_url`, `scope` and its icon URLs as literals, and a root-relative
 * literal points outside an installation served under a prefix: the browser
 * then decides the app's scope is the whole domain, launches it at a URL that
 * is not the app, and shows no icon. None of that reports itself as an error.
 *
 * A service worker has the same problem in a sharper form, because its scope is
 * decided by where it is served from and cannot be widened beyond its own
 * directory. Coming from a route, it is always at the root of the installation
 * and always covers exactly it.
 */
class PwaController extends Controller
{
    /**
     * The page backgrounds set in app.blade.php: white, and near-black in dark
     * mode. Used for the splash screen an installed app shows while it boots,
     * so the launch does not flash a colour the app never has.
     */
    private const string BACKGROUND_COLOR = '#ffffff';

    /**
     * Pictures of the running app, and which shape of screen each one was taken
     * on. Chrome shows them in the install dialog — but only when it has one for
     * the form factor doing the installing, so a desktop with nothing marked
     * `wide` and a phone with nothing marked `narrow` both quietly fall back to
     * the plain one-line prompt.
     *
     * Every screenshot sharing a form factor must also share an aspect ratio,
     * or Chrome drops the lot — which is why these come in matched pairs of the
     * same three screens rather than whatever was lying around.
     *
     * @var list<array{file: string, form_factor: string, label: string}>
     */
    private const array SCREENSHOTS = [
        ['file' => 'screenshot-wide-documents.webp', 'form_factor' => 'wide', 'label' => 'documents'],
        ['file' => 'screenshot-wide-storage.webp', 'form_factor' => 'wide', 'label' => 'storage'],
        ['file' => 'screenshot-wide-document.webp', 'form_factor' => 'wide', 'label' => 'document'],
        ['file' => 'screenshot-narrow-documents.webp', 'form_factor' => 'narrow', 'label' => 'documents'],
        ['file' => 'screenshot-narrow-storage.webp', 'form_factor' => 'narrow', 'label' => 'storage'],
        ['file' => 'screenshot-narrow-document.webp', 'form_factor' => 'narrow', 'label' => 'document'],
    ];

    /**
     * The web app manifest, which is what a browser reads to decide the app can
     * be installed and what to call it once it is.
     *
     * Deliberately reachable without signing in. A manifest behind `auth`
     * redirects to the login page, the browser reads HTML where it expected
     * JSON, and the install option silently never appears.
     *
     * @return JsonResponse The manifest, with the media type browsers expect for it.
     */
    public function manifest(): JsonResponse
    {
        $root = mb_rtrim(url('/'), '/') . '/';
        $name = (string) config('app.name', 'Archivum');

        return response()->json([
            // Stable across deploys and independent of `start_url`, so an
            // installed app is still recognised as the same app if the landing
            // page ever moves.
            'id' => $root,
            'name' => $name,
            'short_name' => $name,
            'description' => __('meta.description'),
            'start_url' => $root,
            'scope' => $root,
            'display' => 'standalone',
            'background_color' => self::BACKGROUND_COLOR,
            'theme_color' => self::BACKGROUND_COLOR,
            'lang' => str_replace('_', '-', app()->getLocale()),
            'icons' => [
                [
                    'src' => asset('icon-192.png'),
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
                [
                    'src' => asset('icon-512.png'),
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
                // Android crops an icon to whatever shape the launcher uses, so
                // this one keeps its artwork inside the safe circle and lets
                // the corners be eaten.
                [
                    'src' => asset('icon-maskable-512.png'),
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'maskable',
                ],
            ],
            'screenshots' => $this->screenshots(),
        ], options: JSON_UNESCAPED_SLASHES)
            ->header('Content-Type', 'application/manifest+json');
    }

    /**
     * The screenshot entries for the manifest, one per file that is actually
     * there.
     *
     * The dimensions and the media type are read from the image rather than
     * written down beside it. A `sizes` that disagrees with the file is rejected
     * by Chrome the same way a missing screenshot is — silently — and keeping
     * the number in two places is what makes them disagree. It also means a
     * fork can drop in screenshots of its own, at whatever size and in whatever
     * format it captured them, without touching this.
     *
     * Chrome is particular about the rest: between 320px and 3840px on each
     * side, the longer side no more than 2.3x the shorter, and every screenshot
     * sharing a form factor sharing an aspect ratio. A file that breaks one of
     * those is dropped by the browser, not by this method.
     *
     * @return list<array<string, string>> The `screenshots` member, empty when this installation ships none.
     */
    private function screenshots(): array
    {
        $screenshots = [];

        foreach (self::SCREENSHOTS as $screenshot) {
            $path = public_path($screenshot['file']);
            $image = is_file($path) ? getimagesize($path) : false;

            if ($image === false) {
                continue;
            }

            $screenshots[] = [
                'src' => asset($screenshot['file']),
                'sizes' => $image[0] . 'x' . $image[1],
                'type' => $image['mime'],
                'form_factor' => $screenshot['form_factor'],
                'label' => __('pwa.screenshot_' . $screenshot['label']),
            ];
        }

        return $screenshots;
    }

    /**
     * The service worker script.
     *
     * The asset build's manifest hash is baked in as the cache name, which is
     * what makes a deploy land: new build, different hash, different script,
     * so the browser sees a changed worker, installs it, and the activate
     * handler drops the previous build's cache on the way through. There is no
     * hash while the Vite dev server is serving assets, and none is needed —
     * nothing it serves is cached.
     *
     * @return Response The script, typed as JavaScript and never held in the HTTP cache.
     */
    public function serviceWorker(): Response
    {
        $script = view('pwa.service-worker', [
            'version' => Vite::manifestHash() ?? 'dev',
            // With the trailing slash asset() strips: the worker matches this
            // as a directory, and `/buildsomething` is not in it.
            'buildBase' => mb_rtrim(asset('build'), '/') . '/',
            'offlineDocument' => view('pwa.offline', [
                'mark' => (string) file_get_contents(public_path('favicon.svg')),
            ])->render(),
        ])->render();

        return response($script)
            ->header('Content-Type', 'text/javascript; charset=utf-8')
            ->header('Cache-Control', 'no-cache');
    }
}
