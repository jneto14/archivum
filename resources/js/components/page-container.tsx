import { usePage } from '@inertiajs/react';
import { AlertCircleIcon } from 'lucide-react';
import * as React from 'react';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { cn } from '@/lib/utils';
import type { PageWidth } from '@/types';

/**
 * The key a refused write travels under when it is not about any one field —
 * a workspace limit, a rule about what may be deleted. Mirrors
 * `App\Support\Refusal::KEY`.
 */
const REFUSAL_KEY = 'general';

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
    // Every page goes through here, which is what makes it the one place a
    // refusal can be shown without each page having to remember to. A
    // validation error is addressed to a field, and a page renders only the
    // ones it has an input for — so a message about a workspace limit, which
    // belongs to no input, used to arrive and be dropped in silence. See
    // App\Support\Refusal.
    const refusal = usePage().props.errors?.[REFUSAL_KEY];

    return (
        <div
            className={cn(
                'mx-auto w-full space-y-6 p-4 sm:p-6',
                widthClasses[width],
                className,
            )}
            {...props}
        >
            {refusal && (
                <Alert variant="destructive">
                    <AlertCircleIcon />
                    <AlertDescription>{refusal}</AlertDescription>
                </Alert>
            )}
            {children}
        </div>
    );
}
