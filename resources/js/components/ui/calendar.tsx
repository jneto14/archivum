import { ChevronLeftIcon, ChevronRightIcon } from 'lucide-react';
import { DayPicker } from 'react-day-picker';
import 'react-day-picker/style.css';

import { cn } from '@/lib/utils';

/**
 * `react-day-picker`, wearing the app's tokens.
 *
 * The library's own stylesheet does the layout and is driven entirely by
 * `--rdp-*` custom properties, so the theming is a block of variable overrides
 * in `resources/css/app.css` rather than a class name mapped onto every
 * internal part — which is both shorter and survives library upgrades, where a
 * hand-mapped `classNames` object silently loses pieces.
 *
 * Those overrides live in the stylesheet and not here on purpose: several of
 * the variables contain an underscore (`--rdp-range_start-color`), and Tailwind
 * rewrites `_` to a space inside arbitrary values, so writing them as utility
 * classes produces broken declarations with no warning.
 */
export function Calendar({
    className,
    ...props
}: React.ComponentProps<typeof DayPicker>) {
    return (
        <DayPicker
            showOutsideDays
            className={cn('text-sm', className)}
            components={{
                PreviousMonthButton: ({ className, ...buttonProps }) => (
                    <button
                        {...buttonProps}
                        className={cn(
                            'rounded-md p-1 hover:bg-accent hover:text-accent-foreground',
                            className,
                        )}
                    >
                        <ChevronLeftIcon className="size-4" />
                    </button>
                ),
                NextMonthButton: ({ className, ...buttonProps }) => (
                    <button
                        {...buttonProps}
                        className={cn(
                            'rounded-md p-1 hover:bg-accent hover:text-accent-foreground',
                            className,
                        )}
                    >
                        <ChevronRightIcon className="size-4" />
                    </button>
                ),
            }}
            {...props}
        />
    );
}
