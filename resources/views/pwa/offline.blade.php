{{-- The page an installed app shows when a navigation cannot reach the server.

     Self-contained on purpose: it is embedded in the service worker and served
     from the cache with no network at all, so it cannot link a stylesheet, a
     font or an image. Everything it needs is inline, and the mark is the same
     SVG the favicon uses. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ __('pwa.offline_title') }} — {{ config('app.name') }}</title>
        <style>
            :root {
                color-scheme: light dark;
                --page: #ffffff;
                --ink: #0a0a0a;
                --muted: #737373;
                --accent: #285e9f;
            }

            @media (prefers-color-scheme: dark) {
                :root {
                    --page: #0a0a0a;
                    --ink: #fafafa;
                    --muted: #a3a3a3;
                    --accent: #5b8dc4;
                }
            }

            body {
                margin: 0;
                min-height: 100vh;
                display: grid;
                place-items: center;
                padding: 2rem 1.5rem;
                box-sizing: border-box;
                background: var(--page);
                color: var(--ink);
                font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
                line-height: 1.5;
            }

            main {
                max-width: 22rem;
                text-align: center;
            }

            svg {
                width: 4rem;
                height: 4rem;
            }

            h1 {
                margin: 1.5rem 0 0.5rem;
                font-size: 1.25rem;
                font-weight: 600;
            }

            p {
                margin: 0;
                color: var(--muted);
            }

            button {
                margin-top: 1.5rem;
                padding: 0.625rem 1.25rem;
                border: 0;
                border-radius: 0.5rem;
                background: var(--accent);
                color: #ffffff;
                font: inherit;
                font-weight: 500;
                cursor: pointer;
            }
        </style>
    </head>
    <body>
        <main>
            {!! $mark !!}
            <h1>{{ __('pwa.offline_title') }}</h1>
            <p>{{ __('pwa.offline_body') }}</p>
            <button type="button" onclick="location.reload()">{{ __('pwa.offline_retry') }}</button>
        </main>
    </body>
</html>
