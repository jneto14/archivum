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
 * surprise.
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
        <div className="flex items-center justify-center gap-2 border-b bg-amber-50 px-4 py-2 text-center text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
            <RefreshCw className="size-4 shrink-0" />
            <span>{t('demo.banner', { datetime: nextReset })}</span>
        </div>
    );
}
