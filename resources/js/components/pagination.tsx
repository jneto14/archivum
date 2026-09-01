import { router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

/**
 * One entry of Laravel's paginator link list: a page number, an ellipsis, or
 * the previous/next entries, which this component ignores in favour of its own.
 */
export type PageLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type Props = {
    /** URL of the previous page, or null when on the first page. */
    prev: string | null;
    /** URL of the next page, or null when on the last page. */
    next: string | null;
    /** Laravel's page link list, for the numbered buttons. Omit for prev/next only. */
    links?: PageLink[];
    from: number | null;
    to: number | null;
    total: number;
};

/** The label Laravel gives its gap entries. */
const ELLIPSIS = '...';

/**
 * Keep only the numbered pages and the ellipsis.
 *
 * Laravel puts its own previous/next entries at the ends of the same list,
 * labelled with HTML entities ("&laquo; Previous"). Filtering by label rather
 * than by position drops them without assuming where in the list they sit.
 */
function pageLinks(links: PageLink[]): PageLink[] {
    return links.filter(
        (link) => link.label === ELLIPSIS || /^\d+$/.test(link.label),
    );
}

/**
 * Pager with a "showing X–Y of Z" summary, prev/next, and numbered pages.
 *
 * Takes explicit URLs rather than a paginator object because the app serialises
 * paginators two different ways: `through()` puts `prev_page_url`/`next_page_url`
 * and `links` at the top level, while a Resource collection nests them under
 * `links`/`meta`. Normalising at the call site keeps both shapes out of here.
 */
export function Pagination({ prev, next, links, from, to, total }: Props) {
    const t = useTranslation();
    const pages = links === undefined ? [] : pageLinks(links);

    const goTo = (url: string | null) => {
        if (url === null) {
            return;
        }

        router.get(url, {}, { preserveState: true, preserveScroll: true });
    };

    return (
        <div className="@container/pager flex flex-wrap items-center justify-between gap-3">
            <span className="text-sm text-muted-foreground">
                {t('pagination.showing', {
                    from: from ?? 0,
                    to: to ?? 0,
                    total,
                })}
            </span>
            <div className="flex flex-wrap items-center justify-end gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    disabled={prev === null}
                    onClick={() => goTo(prev)}
                >
                    {t('pagination.previous')}
                </Button>

                {/*
                 * The numbers are the first thing to go when space is short, and
                 * the space here is set by the sidebar, not the viewport — hence
                 * a container query. Prev/next never disappear.
                 *
                 * The threshold is measured, not guessed: Laravel's widest
                 * window is nine entries, which with the two buttons comes to
                 * ~532px. At `@md` (448px) that overflowed a tablet with the
                 * sidebar open and put "next" out of reach.
                 */}
                {/* A lone "1" is not navigation, so it is not drawn. */}
                {pages.length > 1 && (
                    <div className="hidden flex-wrap items-center gap-1 @xl/pager:flex">
                        {pages.map((link, index) =>
                            link.label === ELLIPSIS ? (
                                <span
                                    key={`ellipsis-${index}`}
                                    aria-hidden="true"
                                    className="px-1.5 text-sm text-muted-foreground"
                                >
                                    {/* Typeset as one character; Laravel sends three dots. */}
                                    &hellip;
                                </span>
                            ) : (
                                <Button
                                    key={link.label}
                                    variant={link.active ? 'default' : 'ghost'}
                                    size="sm"
                                    className={cn(
                                        'min-w-9 tabular-nums',
                                        link.active && 'pointer-events-none',
                                    )}
                                    aria-label={t('pagination.go_to_page', {
                                        page: link.label,
                                    })}
                                    aria-current={
                                        link.active ? 'page' : undefined
                                    }
                                    onClick={() => goTo(link.url)}
                                >
                                    {link.label}
                                </Button>
                            ),
                        )}
                    </div>
                )}

                <Button
                    variant="outline"
                    size="sm"
                    disabled={next === null}
                    onClick={() => goTo(next)}
                >
                    {t('pagination.next')}
                </Button>
            </div>
        </div>
    );
}
