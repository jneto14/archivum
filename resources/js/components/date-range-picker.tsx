import { usePage } from '@inertiajs/react';
import { CalendarIcon, XIcon } from 'lucide-react';
import { useState } from 'react';
import type { DateRange } from 'react-day-picker';
import { enUS, pt } from 'react-day-picker/locale';

import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

const LOCALES = { pt, en: enUS };

/**
 * First day of the week per app locale, as `react-day-picker` counts it
 * (0 = Sunday).
 *
 * Stated here rather than taken from the date-fns locale object, whose `pt`
 * reports Sunday: it leans Brazilian, while this app's Portuguese is European,
 * where the week starts on Monday. Anything not listed falls back to the
 * library's own default for its locale.
 */
const WEEK_STARTS_ON: Record<string, 0 | 1> = { pt: 1, en: 0 };

type Props = {
    /** Start of the range as `YYYY-MM-DD`, or null for open-ended. */
    from: string | null;
    /** End of the range as `YYYY-MM-DD`, or null for open-ended. */
    to: string | null;
    onChange: (from: string | null, to: string | null) => void;
    className?: string;
};

/**
 * Calendar range picker for filtering by document date.
 *
 * Replaces a pair of `<input type="date">`, which render in the *browser's*
 * locale — an app set to Portuguese still showed `mm/dd/yyyy` — and gave no
 * clue which box was the start and which the end.
 *
 * Everything here is deliberately timezone-free. A document date is a calendar
 * date, not an instant: `new Date('2026-08-31')` parses as UTC midnight and
 * renders as the 30th anywhere west of Greenwich, which is the classic
 * off-by-one. Dates are built and read in local components only, and the value
 * exchanged with the server stays the `YYYY-MM-DD` the filter already used.
 */
export function DateRangePicker({ from, to, onChange, className }: Props) {
    const t = useTranslation();
    const { locale } = usePage().props;
    const [open, setOpen] = useState(false);
    const [draft, setDraft] = useState<DateRange | undefined>(undefined);

    const committed: DateRange | undefined = from
        ? { from: parse(from), to: to ? parse(to) : undefined }
        : undefined;

    // While the popover is open the calendar shows the draft, so that clicking
    // the first day of a range does not fire a request and re-render the page
    // out from under the second click.
    const selected = open ? draft : committed;

    const commit = (range: DateRange | undefined) => {
        onChange(
            range?.from ? toIso(range.from) : null,
            range?.to ? toIso(range.to) : null,
        );
    };

    const label = from
        ? formatRange(parse(from), to === null ? null : parse(to), locale)
        : t('documents.index.filter_date_any');

    return (
        <Popover
            open={open}
            onOpenChange={(next) => {
                setOpen(next);

                if (next) {
                    setDraft(committed);

                    return;
                }

                // Whatever was picked before closing is kept: a lone start
                // date is a legitimate open-ended filter, and a single day is
                // a legitimate one-day one.
                if (!sameRange(draft, committed)) {
                    commit(draft);
                }
            }}
        >
            <PopoverTrigger asChild>
                <Button
                    variant="outline"
                    className={cn(
                        'justify-start gap-2 font-normal',
                        from === null && 'text-muted-foreground',
                        className,
                    )}
                >
                    <CalendarIcon className="size-4 shrink-0" />
                    <span className="truncate">{label}</span>
                    {from !== null && (
                        <span
                            role="button"
                            tabIndex={0}
                            aria-label={t('documents.index.filter_date_clear')}
                            className="ml-auto rounded-sm opacity-60 hover:opacity-100"
                            onClick={(event) => {
                                event.preventDefault();
                                event.stopPropagation();
                                onChange(null, null);
                            }}
                            onKeyDown={(event) => {
                                if (
                                    event.key === 'Enter' ||
                                    event.key === ' '
                                ) {
                                    event.preventDefault();
                                    onChange(null, null);
                                }
                            }}
                        >
                            <XIcon className="size-4" />
                        </span>
                    )}
                </Button>
            </PopoverTrigger>
            <PopoverContent className="p-3">
                <Calendar
                    mode="range"
                    numberOfMonths={1}
                    autoFocus
                    locale={LOCALES[locale as keyof typeof LOCALES] ?? enUS}
                    weekStartsOn={WEEK_STARTS_ON[locale]}
                    defaultMonth={selected?.from}
                    selected={selected}
                    onSelect={(range) => {
                        setDraft(range);

                        // A single click yields a one-day range in
                        // react-day-picker, so `to` being set is not enough to
                        // mean "finished" — closing on it made picking a real
                        // range impossible. Only a span of more than one day
                        // ends the interaction; anything else is committed when
                        // the popover closes.
                        if (
                            range?.from &&
                            range.to &&
                            toIso(range.from) !== toIso(range.to)
                        ) {
                            commit(range);
                            setOpen(false);
                        }
                    }}
                />
            </PopoverContent>
        </Popover>
    );
}

/**
 * Parse `YYYY-MM-DD` into a local Date, avoiding `new Date(string)`, which
 * treats a bare date as UTC.
 */
function parse(value: string): Date {
    const [year, month, day] = value.split('-').map(Number);

    return new Date(year, month - 1, day);
}

/** Serialise a Date back to `YYYY-MM-DD` using its local components. */
function toIso(date: Date): string {
    return [
        date.getFullYear(),
        String(date.getMonth() + 1).padStart(2, '0'),
        String(date.getDate()).padStart(2, '0'),
    ].join('-');
}

/** Whether two ranges cover the same days. */
function sameRange(
    a: DateRange | undefined,
    b: DateRange | undefined,
): boolean {
    const key = (range: DateRange | undefined) =>
        [range?.from, range?.to].map((d) => (d ? toIso(d) : '')).join('/');

    return key(a) === key(b);
}

/**
 * Format the range for the trigger, with no timezone applied — see the
 * component docblock.
 *
 * `Intl.formatRange` collapses the parts the two ends share: in Portuguese a
 * September range reads "8–17 de set. de 2026" rather than repeating the month
 * and year and overflowing the button. Spelling the month out is deliberate —
 * a numeric date would put us back where the native inputs were, with no way
 * to tell 08/09 from 09/08.
 */
function formatRange(from: Date, to: Date | null, locale: string): string {
    const formatter = new Intl.DateTimeFormat(locale, { dateStyle: 'medium' });

    return to === null
        ? formatter.format(from)
        : formatter.formatRange(from, to);
}
