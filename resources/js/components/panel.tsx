import * as React from 'react';
import { cn } from '@/lib/utils';

/**
 * Bordered container for lists and tables. Use this rather than `Card` for
 * collections; `Card` stays for the padded, titled sections of detail and
 * settings screens.
 */
export function Panel({
    className,
    children,
    ...props
}: React.ComponentProps<'div'>) {
    return (
        <div
            className={cn('overflow-hidden rounded-xl border', className)}
            {...props}
        >
            {children}
        </div>
    );
}

/**
 * Optional toolbar strip at the top of a `Panel`, for a count plus an action.
 */
export function PanelHeader({
    className,
    children,
    ...props
}: React.ComponentProps<'div'>) {
    return (
        <div
            className={cn(
                'flex flex-wrap items-center justify-between gap-2 border-b bg-muted px-4 py-3',
                className,
            )}
            {...props}
        >
            {children}
        </div>
    );
}
