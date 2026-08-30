import * as React from 'react';
import { cn } from '@/lib/utils';
import type { PageWidth } from '@/types';

/**
 * The one place page width is decided. Pages must not set their own `max-w-*`
 * or padding — that is what let every screen drift to a different width.
 *
 * Pick by what the page *is*, never by how much content it happens to hold —
 * "this list feels wide" is the judgement call that produced the original
 * drift, where two equivalent admin lists sat at 3xl and 4xl.
 *
 * - `narrow` — a single column of form fields: forms and settings screens.
 * - `default` — everything else. Every list, every detail screen, the
 *   dashboard. A page with more columns does not get to opt out.
 * - `wide` — reserved for laying out N panels side by side, where the column
 *   count is driven by data. Today that is only the storage browser.
 *
 * A page that renders different states (loaded vs. onboarding) must use the
 * same width for all of them.
 */
const widthClasses: Record<PageWidth, string> = {
    narrow: 'max-w-3xl',
    default: 'max-w-5xl',
    wide: 'max-w-7xl',
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
