import * as React from 'react';
import { cn } from '@/lib/utils';
import type { PageWidth } from '@/types';

/**
 * The one place page width is decided. Pages must not set their own `max-w-*`
 * or padding — that is what let every screen drift to a different width.
 *
 * - `narrow` — forms and settings, where long line lengths hurt readability.
 * - `default` — detail screens mixing a main column with a side column.
 * - `wide` — dense list and table screens that need the horizontal room.
 * - `full` — screens managing their own width, e.g. multi-column browsers.
 */
const widthClasses: Record<PageWidth, string> = {
    narrow: 'max-w-3xl',
    default: 'max-w-5xl',
    wide: 'max-w-7xl',
    full: 'max-w-none',
};

type Props = React.ComponentProps<'div'> & {
    width?: PageWidth;
};

export function PageContainer({
    width = 'default',
    className,
    children,
    ...props
}: Props) {
    return (
        <div
            className={cn(
                'mx-auto w-full space-y-6 p-6',
                widthClasses[width],
                className,
            )}
            {...props}
        >
            {children}
        </div>
    );
}
