import { usePage } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';

import { useTranslation } from '@/hooks/use-translation';

/**
 * Tells a visitor to a public demo that everything they do here is temporary,
 * and when it goes.
 *
 * Naming the deadline is the point. A demo that silently wipes overnight
 * punishes exactly the visitor who took it seriously enough to spend ten
 * minutes setting something up, so the hour is stated rather than left as a
 * surprise. The time is formatted in the viewer's own timezone, which is why it
 * may not read as the hour the operator configured.
 *
 * Deliberately quiet. This is context, not a warning: it says what kind of
 * installation you are looking at, and nothing is wrong. Drawn on `muted` like
 * every other secondary surface in the app rather than in a caution colour —
 * the red-tinted blocks in this codebase are reserved for destructive actions,
 * and borrowing that weight here would make an ordinary demo look broken.
 *
 * Renders nothing on an ordinary installation — the `demo` page prop is null
 * unless DEMO_MODE is on.
 */
export function DemoBanner() {
    const t = useTranslation();
    const { demo, locale } = usePage().props;

    if (demo === null) {
        return null;
    }

    const nextReset = new Intl.DateTimeFormat(locale, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(demo.nextResetAt));

    return (
        <div className="flex items-center justify-center gap-2 border-b bg-muted px-4 py-1.5 text-center text-xs text-muted-foreground">
            <RefreshCw className="size-3.5 shrink-0" aria-hidden="true" />
            <span>{t('demo.banner', { datetime: nextReset })}</span>
        </div>
    );
}
