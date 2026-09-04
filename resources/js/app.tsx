import { createInertiaApp } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { applyPathPrefix } from '@/lib/path-prefix';
import { registerServiceWorker } from '@/lib/service-worker';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// Before anything renders, so no URL is read at its build-time value first.
// Eager on purpose: a lazily loaded route module would be the one that got
// away. See lib/path-prefix.ts for why this is the seam.
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
            // Opened by scanning a QR code on a phone that was never signed
            // in — the desktop app's sidebar shell has no business there.
            case name.startsWith('capture/'):
                return null;
            // A sheet of labels on its way to a printer, for the same reason.
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
    withApp(app) {
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

// This will set light / dark mode on load...
initializeTheme();

// After the route definitions were rewritten above, so the worker is asked for
// at the URL this installation actually serves it from.
registerServiceWorker();
