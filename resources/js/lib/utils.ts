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

/**
 * An id for a row that only exists in the browser — a queued file, an unsaved
 * form row — unique enough to be a React key and nothing more.
 *
 * `crypto.randomUUID()` is not available here. It is restricted to secure
 * contexts, and this application is routinely served over plain HTTP on a LAN
 * (a self-hosted box at http://192.168.x.x, which is also how a phone reaches
 * the capture page), where it is simply undefined — attaching a file threw
 * `crypto.randomUUID is not a function` and the page died.
 *
 * `crypto.getRandomValues()` carries no such restriction, so it is the one used
 * where present; the last resort is only for a runtime with no Web Crypto at
 * all. Neither the value nor its randomness ever leaves the page.
 */
export function randomId(): string {
    const bytes = new Uint8Array(16);

    if (typeof crypto !== 'undefined' && 'getRandomValues' in crypto) {
        crypto.getRandomValues(bytes);
    } else {
        for (let index = 0; index < bytes.length; index++) {
            bytes[index] = Math.floor(Math.random() * 256);
        }
    }

    return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join(
        '',
    );
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
