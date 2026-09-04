import type { ReactNode } from 'react';

type Props = {
    title: string;
    description?: ReactNode;
    /** Actions rendered at the trailing edge, e.g. a "New document" button. */
    children?: ReactNode;
};

/**
 * The `h1` block at the top of every page. Distinct from `Heading`, which is an
 * `h2` for sections *within* a page (settings panels) — don't merge the two.
 */
export function PageHeader({ title, description, children }: Props) {
    return (
        <div className="@container/page-header flex flex-wrap items-end justify-between gap-4">
            <div className="min-w-0 space-y-1">
                <h1 className="text-2xl font-semibold tracking-tight break-words">
                    {title}
                </h1>
                {description !== undefined && (
                    <div className="text-sm text-muted-foreground">
                        {description}
                    </div>
                )}
            </div>
            {children !== undefined && (
                /*
                 * Narrow, the actions take the whole line under the title and
                 * share it evenly, wrapping as a grid rather than trailing off
                 * to the right — three controls that used to leave the primary
                 * one orphaned on a line of its own.
                 *
                 * They stretch rather than being given a column count, because
                 * this component does not know how many actions a page has or
                 * how long its labels are in every language. A flex item never
                 * shrinks below its own content, so a long label makes the row
                 * wrap; a grid track would let it overflow the page instead.
                 *
                 * The container decides, not the viewport: the sidebar moves
                 * the content area by 208px without the viewport changing.
                 */
                <div className="flex w-full min-w-0 flex-wrap items-center gap-2 *:flex-1 *:basis-40 @lg/page-header:w-auto @lg/page-header:*:flex-none @lg/page-header:*:basis-auto">
                    {children}
                </div>
            )}
        </div>
    );
}
