import type { ReactNode } from 'react';

type Props = {
    title: string;
    description?: ReactNode;
    /** Optional call to action, e.g. a "Create the first one" button. */
    children?: ReactNode;
};

export function EmptyState({ title, description, children }: Props) {
    return (
        <div className="rounded-xl border border-dashed p-12 text-center">
            <div className="font-semibold">{title}</div>
            {description !== undefined && (
                <div className="mt-1 text-sm text-muted-foreground">
                    {description}
                </div>
            )}
            {children !== undefined && (
                <div className="mt-4 flex justify-center">{children}</div>
            )}
        </div>
    );
}
