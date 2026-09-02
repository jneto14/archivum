import { usePage } from '@inertiajs/react';

import { useTranslation } from '@/hooks/use-translation';

/**
 * Prints the demo's credentials on the login screen.
 *
 * A public demo has nobody to ask for an account, so the credentials have to be
 * on the door.
 *
 * Laid out as a definition list rather than two loose lines: the values are the
 * point, and pairing each with its own label is what stops someone typing the
 * password into the email field. They stay selectable text rather than being
 * pre-filled into the form, so a password manager is not offered a set of
 * shared credentials to save, and the real sign-in is still what gets
 * demonstrated.
 *
 * Drawn on `muted` like the rest of the app's secondary surfaces. It is a note,
 * not an alert — nothing here needs the weight this codebase reserves for
 * destructive actions.
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
        <div className="rounded-lg border bg-muted px-4 py-3">
            <p className="text-sm font-medium">{t('demo.credentials')}</p>

            <dl className="mt-2 grid grid-cols-[auto_1fr] items-baseline gap-x-3 gap-y-1 text-sm">
                <dt className="text-muted-foreground">
                    {t('auth.login.email_label')}
                </dt>
                <dd className="truncate font-mono text-xs select-all">
                    {demo.email}
                </dd>

                <dt className="text-muted-foreground">
                    {t('auth.login.password_label')}
                </dt>
                <dd className="truncate font-mono text-xs select-all">
                    {demo.password}
                </dd>
            </dl>
        </div>
    );
}
