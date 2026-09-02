import { renderHook } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { useDateFormatter } from '@/hooks/use-date-formatter';

/**
 * Two settings decide how a timestamp reads, and neither of them is the
 * machine's: the workspace's language and the signed-in user's saved timezone.
 * Both are easy to appear correct on the developer's own laptop, where the
 * browser already agrees with them.
 *
 * So the tests run somewhere the machine disagrees, and check the output moved.
 */
const page = vi.hoisted(() => ({
    props: {
        locale: 'en',
        auth: { user: { timezone: null as string | null } },
    },
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => page,
}));

/** Late evening in London: the instant where the day depends on where you are. */
const INSTANT = '2026-08-31T22:30:00Z';

function formatters() {
    return renderHook(() => useDateFormatter()).result.current;
}

beforeEach(() => {
    vi.stubEnv('TZ', 'Europe/London');
    page.props.locale = 'en';
    page.props.auth.user.timezone = null;
});

afterEach(() => {
    vi.unstubAllEnvs();
});

it("uses the user's saved timezone rather than the machine's", () => {
    page.props.auth.user.timezone = 'Pacific/Kiritimati';

    // UTC+14, so 22:30 on the 31st in London is already the 1st there.
    expect(formatters().formatDateTime(INSTANT)).toMatch(/9\D+1\D+26/);
});

it('falls back to the machine timezone when the user has saved none', () => {
    expect(formatters().formatDateTime(INSTANT)).toMatch(/8\D+31\D+26/);
});

it('formats in the app locale, not the browser one', () => {
    page.props.auth.user.timezone = 'Europe/Lisbon';
    const inEnglish = formatters().formatDate(INSTANT);

    page.props.locale = 'pt';
    const inPortuguese = formatters().formatDate(INSTANT);

    // Month first against day first — the difference a person actually sees,
    // and the one the year's width would not tell us anything about.
    expect(inEnglish).toMatch(/^8\/31\//);
    expect(inPortuguese).toMatch(/^31\/08\//);
});

it('drops the time of day', () => {
    page.props.auth.user.timezone = 'Europe/Lisbon';

    expect(formatters().formatDate(INSTANT)).not.toMatch(/\d\d:\d\d/);
});
