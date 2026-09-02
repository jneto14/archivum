import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import babel from '@rolldown/plugin-babel';
import tailwindcss from '@tailwindcss/vite';
import react, { reactCompilerPreset } from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';

export default defineConfig({
    /*
     * Relative, so a chunk finds its siblings from its own URL.
     *
     * laravel-vite-plugin otherwise sets Vite's base from ASSET_URL at *build*
     * time, which bakes `/build/assets/...` into every chunk-to-chunk import.
     * The published image is built once, without knowing where it will be
     * served, so under a path prefix those imports ask the domain root and the
     * application never boots — and setting ASSET_URL afterwards cannot reach
     * them, because they are inside the JavaScript rather than rendered by
     * Laravel.
     *
     * With './' the browser resolves each import against the importing module,
     * which is already under whatever prefix the entry was loaded from. The
     * entry itself, the preloads and the stylesheet links still come from
     * Laravel's manifest through asset(), so they are unaffected.
     *
     * One thing this does break: the font stylesheet is inlined into the HTML,
     * where './' resolves against the *page* rather than the stylesheet. See
     * App\Support\FontStyles, which maps those back through asset().
     */
    base: './',
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        inertia(),
        react(),
        babel({
            presets: [reactCompilerPreset()],
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
    ],
    server: {
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/vendor/**',
            ],
        },
    },
});
