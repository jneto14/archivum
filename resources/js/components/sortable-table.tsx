import { router } from '@inertiajs/react';
import { ArrowDownIcon, ArrowUpIcon, ArrowUpDownIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { TableHead } from '@/components/ui/table';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

/** The order a listing is currently read in, as the server resolved it. */
export type SortState = {
    key: string;
    direction: 'asc' | 'desc';
};

/**
 * One column a listing offers to sort by.
 *
 * `descendingFirst` is for the columns where the interesting end is the high
 * one: a date, a count. Clicking "Date" and being shown the oldest document in
 * the archive is not what anyone meant.
 */
export type SortColumn = {
    key: string;
    label: string;
    descendingFirst?: boolean;
};

/**
 * The behaviour behind both controls: a page declares its columns once and the
 * clickable heads and the menu drive the same query string.
 *
 * Two controls for one choice, because neither covers the ground alone. Heads
 * are the obvious thing to click with a mouse, but a table scrolls sideways on
 * a phone — dragging across to find a head is not a sort control — and the
 * documents list has a card layout with no heads at all. The menu is rendered
 * at every width rather than below a breakpoint: a control that appears only
 * when the container is narrow is a rule that has to be right about a width,
 * and ARC-113 shipped one that was two pixels out and rendered for nobody.
 *
 * A plain function rather than a hook — it holds no state of its own, since the
 * order lives in the URL and comes back as a prop — so a page may call it after
 * an early return, which several of them have.
 *
 * @param url The listing's own URL, which the sort is applied to.
 * @param sort The order the server resolved for this request.
 * @param columns The columns this listing offers, in the order they are shown.
 * @param query Anything else that has to survive the visit — filters, mostly.
 */
export function tableSort(
    url: string,
    sort: SortState,
    columns: SortColumn[],
    query: Record<string, unknown> = {},
) {
    const sortBy = (key: string, direction: SortState['direction']) => {
        router.get(
            url,
            { ...query, sort: key, direction },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    /**
     * Clicking the active column reverses it; clicking another starts it at
     * whichever end that column is usually read from.
     */
    const toggle = (key: string) => {
        const column = columns.find((candidate) => candidate.key === key);

        if (key === sort.key) {
            sortBy(key, sort.direction === 'asc' ? 'desc' : 'asc');

            return;
        }

        sortBy(key, column?.descendingFirst === true ? 'desc' : 'asc');
    };

    return { sort, columns, sortBy, toggle };
}

export type TableSort = ReturnType<typeof tableSort>;

type HeadProps = {
    /** The column this head sorts by; must be one the listing declared. */
    sortKey: string;
    sorting: TableSort;
    className?: string;
    children: ReactNode;
};

/**
 * A column head that sorts by its column.
 *
 * The arrow is drawn on every sortable head so the whole row reads as
 * clickable, but only carries its full weight on the active one — an arrow at
 * the same strength everywhere says nothing about which column is in effect.
 */
export function SortableTableHead({
    sortKey,
    sorting,
    className,
    children,
}: HeadProps) {
    const active = sorting.sort.key === sortKey;
    const ascending = sorting.sort.direction === 'asc';

    const Arrow = !active
        ? ArrowUpDownIcon
        : ascending
          ? ArrowUpIcon
          : ArrowDownIcon;

    return (
        <TableHead
            className={cn('p-0', className)}
            aria-sort={
                active ? (ascending ? 'ascending' : 'descending') : 'none'
            }
        >
            <button
                type="button"
                onClick={() => sorting.toggle(sortKey)}
                className="group flex h-10 w-full items-center gap-1.5 px-2 text-left font-medium hover:text-foreground"
            >
                {children}
                <Arrow
                    aria-hidden="true"
                    className={cn(
                        'size-3.5 shrink-0',
                        active
                            ? 'text-foreground'
                            : 'text-muted-foreground/40 group-hover:text-muted-foreground',
                    )}
                />
            </button>
        </TableHead>
    );
}

/**
 * The same columns as a menu, for the widths and the layouts where there is no
 * head to click.
 */
export function SortMenu({
    sorting,
    className,
}: {
    sorting: TableSort;
    className?: string;
}) {
    const t = useTranslation();
    const active = sorting.columns.find(
        (column) => column.key === sorting.sort.key,
    );
    const ascending = sorting.sort.direction === 'asc';

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                {/*
                 * The column name alone, with the direction as its icon. Adding
                 * "Sorted by" in front doubles the width of a control that has
                 * to fit beside the other listing actions on a phone; the
                 * accessible name says it instead.
                 */}
                <Button
                    variant="outline"
                    size="sm"
                    className={cn('min-w-0', className)}
                    aria-label={t('sort.by', { column: active?.label ?? '' })}
                >
                    {ascending ? (
                        <ArrowUpIcon aria-hidden="true" />
                    ) : (
                        <ArrowDownIcon aria-hidden="true" />
                    )}
                    <span className="truncate">{active?.label ?? ''}</span>
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuLabel>{t('sort.label')}</DropdownMenuLabel>
                {sorting.columns.map((column) => (
                    <DropdownMenuItem
                        key={column.key}
                        onSelect={() => sorting.toggle(column.key)}
                    >
                        <span
                            className={cn(
                                column.key === sorting.sort.key &&
                                    'font-medium',
                            )}
                        >
                            {column.label}
                        </span>
                    </DropdownMenuItem>
                ))}
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    onSelect={() => sorting.sortBy(sorting.sort.key, 'asc')}
                >
                    <ArrowUpIcon aria-hidden="true" />
                    <span className={cn(ascending && 'font-medium')}>
                        {t('sort.ascending')}
                    </span>
                </DropdownMenuItem>
                <DropdownMenuItem
                    onSelect={() => sorting.sortBy(sorting.sort.key, 'desc')}
                >
                    <ArrowDownIcon aria-hidden="true" />
                    <span className={cn(!ascending && 'font-medium')}>
                        {t('sort.descending')}
                    </span>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
