import { usePage } from '@inertiajs/react';
import { formatDate, formatDateTime } from '@/lib/utils';

/**
 * Binds the app's chosen locale and the current user's saved timezone
 * preference into ready-to-call date formatters, so pages don't each have
 * to pull `locale`/`auth.user.timezone` out of shared page props themselves.
 */
export function useDateFormatter() {
    const { locale, auth } = usePage().props;
    const timezone = auth.user.timezone;

    return {
        formatDate: (iso: string) => formatDate(iso, locale, timezone),
        formatDateTime: (iso: string) => formatDateTime(iso, locale, timezone),
    };
}
