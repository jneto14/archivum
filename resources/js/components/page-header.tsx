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
        <div className="flex flex-wrap items-end justify-between gap-4">
            <div className="space-y-1">
                <h1 className="text-2xl font-semibold tracking-tight">
                    {title}
                </h1>
                {description !== undefined && (
                    <div className="text-sm text-muted-foreground">
                        {description}
                    </div>
                )}
            </div>
            {children !== undefined && (
                <div className="flex flex-wrap items-center gap-2">
                    {children}
                </div>
            )}
        </div>
    );
}
