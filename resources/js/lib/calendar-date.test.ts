import { afterEach, describe, expect, it, vi } from 'vitest';
import { parseCalendarDate, toCalendarDate } from '@/lib/calendar-date';

/**
 * These two functions exist for one reason: `new Date('2026-08-31')` is parsed
 * as UTC midnight, which is the 30th anywhere west of Greenwich, and
 * `toISOString()` converts back the same way. Half the planet gets a date that
 * is a day out.
 *
 * So the timezone is the test. Run the round trip in one zone and both the
 * correct implementation and the naive one pass — which is exactly how the
 * defect survived being fixed once already, in the documents filter, before
 * coming back in the document form (ARC-94).
 */
const TIMEZONES = [
    'Pacific/Kiritimati', // UTC+14, the far edge in one direction
    'Europe/Lisbon',
    'UTC',
    'America/Los_Angeles', // UTC-8, where the naive parse loses a day
    'Pacific/Niue', // UTC-11, the far edge in the other
];

afterEach(() => {
    vi.unstubAllEnvs();
});

describe.each(TIMEZONES)('in %s', (timezone) => {
    it('reads a wire date as that day on the local calendar', () => {
        vi.stubEnv('TZ', timezone);

        const parsed = parseCalendarDate('2026-08-31');

        expect([
            parsed.getFullYear(),
            parsed.getMonth() + 1,
            parsed.getDate(),
        ]).toEqual([2026, 8, 31]);
    });

    /**
     * On its own this one proves less than it looks: the naive pair cancels
     * out, since `new Date('2026-08-31').toISOString()` returns the string it
     * was given. It is the parse test above that catches the defect. Both are
     * kept, because the round trip is what the form actually does.
     */
    it('gives back the wire date it was handed', () => {
        vi.stubEnv('TZ', timezone);

        expect(toCalendarDate(parseCalendarDate('2026-08-31'))).toBe(
            '2026-08-31',
        );
    });

    /**
     * The first of the month at midnight is where an hour's drift in either
     * direction lands in a different month, and a New Year's Day is where it
     * lands in a different year.
     */
    it.each(['2026-01-01', '2026-03-29', '2026-12-31'])(
        'survives %s, where a drift of an hour changes the month',
        (wire) => {
            vi.stubEnv('TZ', timezone);

            expect(toCalendarDate(parseCalendarDate(wire))).toBe(wire);
        },
    );
});

it('pads a single-digit month and day, so the wire format stays fixed-width', () => {
    expect(toCalendarDate(new Date(2026, 0, 5))).toBe('2026-01-05');
});
