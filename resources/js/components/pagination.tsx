import { router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';

type Props = {
    /** URL of the previous page, or null when on the first page. */
    prev: string | null;
    /** URL of the next page, or null when on the last page. */
    next: string | null;
    from: number | null;
    to: number | null;
    total: number;
};

/**
 * Prev/next pager with a "showing X–Y of Z" summary.
 *
 * Takes explicit URLs rather than a paginator object because the app serialises
 * paginators two different ways: `through()` puts `prev_page_url`/`next_page_url`
 * at the top level, while a Resource collection nests them under `links`.
 */
export function Pagination({ prev, next, from, to, total }: Props) {
    const t = useTranslation();

    const goTo = (url: string | null) => {
        if (url === null) {
            return;
        }

        router.get(url, {}, { preserveState: true, preserveScroll: true });
    };

    return (
        <div className="flex flex-wrap items-center justify-between gap-3">
            <span className="text-sm text-muted-foreground">
                {t('pagination.showing', {
                    from: from ?? 0,
                    to: to ?? 0,
                    total,
                })}
            </span>
            <div className="flex gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    disabled={prev === null}
                    onClick={() => goTo(prev)}
                >
                    {t('pagination.previous')}
                </Button>
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
