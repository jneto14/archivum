import { usePage } from '@inertiajs/react';
import { CalendarIcon, XIcon } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { parseCalendarDate, toCalendarDate } from '@/lib/calendar-date';
import { calendarLocaleProps } from '@/lib/calendar-locale';
import { cn } from '@/lib/utils';

type Props = {
    /** The selected day as `YYYY-MM-DD`, or null when the field is empty. */
    value: string | null;
    onChange: (value: string | null) => void;
    /** Shown on the trigger while nothing is selected. */
    placeholder: string;
    /** Accessible label for the button that empties the field. */
    clearLabel: string;
    id?: string;
    className?: string;
};

/**
 * Single-day picker, the counterpart to {@link DateRangePicker}.
 *
 * Replaces `<input type="date">`, which renders in the *browser's* locale
 * rather than the app's: an installation set to Portuguese still showed
 * `mm/dd/yyyy` and an English calendar to anyone whose browser was set to
 * en-US (ARC-94). The locale here comes from the shared `locale` page prop, so
 * it follows the application.
 *
 * Timezone-free by construction, like the range picker: see
 * {@link parseCalendarDate} for why nothing in this file may reach for
 * `new Date(string)` or `toISOString()`. The value exchanged with the server
 * stays the `YYYY-MM-DD` the form already submitted, so the backend is
 * untouched.
 */
export function DatePicker({
    value,
    onChange,
    placeholder,
    clearLabel,
    id,
    className,
}: Props) {
    const { locale } = usePage().props;
    const [open, setOpen] = useState(false);

    const selected = value ? parseCalendarDate(value) : undefined;

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    id={id}
                    type="button"
                    variant="outline"
                    className={cn(
                        'justify-start gap-2 font-normal',
                        value === null && 'text-muted-foreground',
                        className,
                    )}
                >
                    <CalendarIcon className="size-4 shrink-0" />
                    <span className="truncate">
                        {selected
                            ? new Intl.DateTimeFormat(locale, {
                                  dateStyle: 'medium',
                              }).format(selected)
                            : placeholder}
                    </span>
                    {value !== null && (
                        <span
                            role="button"
                            tabIndex={0}
                            aria-label={clearLabel}
                            className="ml-auto rounded-sm opacity-60 hover:opacity-100"
                            onClick={(event) => {
                                event.preventDefault();
                                event.stopPropagation();
                                onChange(null);
                            }}
                            onKeyDown={(event) => {
                                if (
                                    event.key === 'Enter' ||
                                    event.key === ' '
                                ) {
                                    event.preventDefault();
                                    onChange(null);
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
                    mode="single"
                    numberOfMonths={1}
                    autoFocus
                    {...calendarLocaleProps(locale)}
                    defaultMonth={selected}
                    selected={selected}
                    onSelect={(day) => {
                        onChange(day ? toCalendarDate(day) : null);
                        setOpen(false);
                    }}
                />
            </PopoverContent>
        </Popover>
    );
}
