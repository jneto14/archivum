<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        {{-- Through asset() so they follow ASSET_URL: a root-relative path
             here would look outside an installation served under a prefix. --}}
        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
        <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
        <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

        {{-- The path this installation is served under, for the JavaScript
             bundle, whose route URLs were compiled without it. Read from a meta
             tag because it must be known before the first route module is used.
             Empty on an installation with a hostname of its own. --}}
        <meta name="app-path-prefix" content="{{ config('archivum.path_prefix') }}">

        {{-- An internal application has nothing to rank, and a login page in a
             search index quietly announces that an organisation keeps an
             archive at this address. The X-Robots-Tag header on every response
             says the same thing for the routes that serve files rather than
             HTML. --}}
        <meta name="robots" content="noindex, nofollow">

        {{-- The card is for links people paste into a chat, not for search
             engines. Shared with every page: an Archivum install is one
             product, and no screen behind the login is worth a preview card of
             its own. --}}
        @php($metaDescription = __('meta.description'))
        <meta name="description" content="{{ $metaDescription }}">
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="{{ config('app.name') }}">
        <meta property="og:title" content="{{ config('app.name') }}">
        <meta property="og:description" content="{{ $metaDescription }}">
        <meta property="og:url" content="{{ url()->current() }}">
        <meta property="og:image" content="{{ url('/og-image.png') }}">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta property="og:locale" content="{{ str_replace('-', '_', app()->getLocale()) }}">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="{{ config('app.name') }}">
        <meta name="twitter:description" content="{{ $metaDescription }}">
        <meta name="twitter:image" content="{{ url('/og-image.png') }}">

        {{-- Not @fonts: the stylesheet it inlines carries root-relative asset
             URLs written at build time, which point outside an installation
             served under a path. See App\Support\FontStyles. --}}
        {!! App\Support\FontStyles::render() !!}

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Archivum') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
