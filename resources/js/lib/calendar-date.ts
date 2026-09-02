/**
 * Helpers for calendar dates — a day on a calendar, not an instant in time.
 *
 * A document date is the date printed on the document. It has no time and no
 * timezone, and the wire format either side of it is the `YYYY-MM-DD` the
 * server already stores.
 *
 * The trap these exist to avoid: `new Date('2026-08-31')` is parsed as UTC
 * midnight, which is the 30th anywhere west of Greenwich, and
 * `Date.prototype.toISOString()` converts back through UTC the same way. Either
 * one shifts a date by a day for roughly half the planet. Both directions here
 * go through the local calendar components instead, so a date survives the
 * round trip in every timezone.
 *
 * They live in their own module rather than inside a component because both the
 * range picker and the single-date picker need them, and two divergent copies of
 * an off-by-one fix is how the off-by-one comes back.
 */

/** Parse `YYYY-MM-DD` into a Date at local midnight on that calendar day. */
export function parseCalendarDate(value: string): Date {
    const [year, month, day] = value.split('-').map(Number);

    return new Date(year, month - 1, day);
}

/** Serialise a Date back to `YYYY-MM-DD` using its local calendar components. */
export function toCalendarDate(date: Date): string {
    return [
        date.getFullYear(),
        String(date.getMonth() + 1).padStart(2, '0'),
        String(date.getDate()).padStart(2, '0'),
    ].join('-');
}
