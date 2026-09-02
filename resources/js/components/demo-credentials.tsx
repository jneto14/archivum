import { usePage } from '@inertiajs/react';

import { useTranslation } from '@/hooks/use-translation';

/**
 * Prints the demo's credentials on the login screen.
 *
 * A public demo has nobody to ask for an account, so the credentials have to be
 * on the door. Rendered as selectable text rather than pre-filled into the form
 * so a password manager does not offer to save them, and so the fields still
 * demonstrate the real sign-in.
 *
 * Renders nothing on an ordinary installation — the `demo` page prop is null
 * unless DEMO_MODE is on.
 */
export function DemoCredentials() {
    const t = useTranslation();
    const { demo } = usePage().props;

    if (demo === null) {
        return null;
    }

    return (
        <div className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-200">
            <p className="font-medium">{t('demo.credentials')}</p>
            <p className="mt-1 font-mono text-xs break-all select-all">
                {demo.email}
            </p>
            <p className="font-mono text-xs break-all select-all">
                {demo.password}
            </p>
        </div>
    );
}
