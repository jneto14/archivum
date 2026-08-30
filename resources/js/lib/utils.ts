import type { InertiaLinkProps } from '@inertiajs/react';
import { clsx } from 'clsx';
import type { ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function toUrl(url: NonNullable<InertiaLinkProps['href']>): string {
    return typeof url === 'string' ? url : url.url;
}

export function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    const units = ['KB', 'MB', 'GB'];
    let value = bytes / 1024;
    let unitIndex = 0;

    while (value >= 1024 && unitIndex < units.length - 1) {
        value /= 1024;
        unitIndex++;
    }

    return `${value.toFixed(1)} ${units[unitIndex]}`;
}

/**
 * Formats an ISO timestamp using the app's chosen locale and, when set, the
 * user's saved timezone preference — instead of the browser's uncontrolled
 * defaults. A null timezone falls back to the browser's local timezone,
 * matching the app's prior (unconfigurable) behavior.
 */
export function formatDateTime(
    iso: string,
    locale: string,
    timezone: string | null,
): string {
    return new Intl.DateTimeFormat(locale, {
        dateStyle: 'short',
        timeStyle: 'short',
        timeZone: timezone ?? undefined,
    }).format(new Date(iso));
}

/** Same as {@link formatDateTime}, but without the time-of-day component. */
export function formatDate(
    iso: string,
    locale: string,
    timezone: string | null,
): string {
    return new Intl.DateTimeFormat(locale, {
        dateStyle: 'short',
        timeZone: timezone ?? undefined,
    }).format(new Date(iso));
}
