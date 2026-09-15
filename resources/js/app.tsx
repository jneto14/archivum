import { createInertiaApp } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { applyPathPrefix } from '@/lib/path-prefix';
import { registerServiceWorker } from '@/lib/service-worker';

/**
 * The installation's own name, taken from the server on the first render.
 *
 * Not `import.meta.env.VITE_APP_NAME`, which is resolved when the bundle is
 * built. The published image is built once, in CI, with no `.env` of its own —
 * `.dockerignore` excludes it deliberately — so a build-time name is the same
 * for every installation that pulls the image, and setting `APP_NAME` on a
 * server does nothing. It shipped as the framework's default, which is how a
 * demo came to call itself Laravel.
 *
 * `config('app.name')` already reaches the page as the shared `name` prop, and
 * `withApp` runs before anything renders a title (ARC-120).
 */
let appName = 'Archivum';

// Eager, and before anything renders: a lazily loaded route module would
// read its URL at build-time value, the one this call exists to rewrite.
applyPathPrefix(
    import.meta.glob(['./routes/**/*.ts', './actions/**/*.ts'], {
        eager: true,
    }),
);

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
                return null;
            case name.startsWith('capture/'):
                return null;
            case name === 'organization/labels':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app, { page }) {
        appName = page.props.name || appName;

        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

initializeTheme();

// After the route definitions were rewritten above, so the worker is asked for
// at the URL this installation actually serves it from.
registerServiceWorker();
