import { Head, Link, router, setLayoutProps } from '@inertiajs/react';
import { useState } from 'react';
import AttachmentController from '@/actions/App/Http/Controllers/Documents/AttachmentController';
import { EmptyState } from '@/components/empty-state';
import Heading from '@/components/heading';
import { PageContainer } from '@/components/page-container';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import type { PageLink } from '@/components/pagination';
import { Panel, PanelHeader } from '@/components/panel';
import { ReviewRow } from '@/components/review-row';
import type { ReviewRowDocument } from '@/components/review-row';
import { SortMenu, tableSort } from '@/components/sortable-table';
import type { SortState } from '@/components/sortable-table';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useTranslation } from '@/hooks/use-translation';
import type { TranslationKey } from '@/lib/translations';
import {
    index as documentsIndex,
    review as documentsReview,
    show as documentShow,
} from '@/routes/documents';
import { bulk as reviewBulk } from '@/routes/documents/review';
import { update as updateIntakeLabel } from '@/routes/workspaces/intake-labels';

type CandidateLabel = {
    id: string;
    kind: string;
    field: string;
    label: string;
    support: number;
    documents: { id: string; title: string }[];
};

/** Which kind of finding the queue is showing. See App\Enums\ReviewFilter. */
type Filter = 'all' | 'suggestions' | 'readings' | 'duplicates';

/** What a bulk answer does. See App\Enums\BulkReviewAction. */
type BulkAction =
    'accept_suggestions' | 'dismiss_readings' | 'dismiss_duplicates';

type Props = {
    workspaceId: string;
    filter: Filter;
    sort: SortState;
    documents: ReviewRowDocument[];
    counts: Record<Filter, number>;
    pagination: {
        prev: string | null;
        next: string | null;
        links: PageLink[];
        from: number | null;
        to: number | null;
        total: number;
    };
    labels: CandidateLabel[];
};

const FILTERS: { value: Filter; label: TranslationKey }[] = [
    { value: 'all', label: 'documents.review.filter_all' },
    { value: 'suggestions', label: 'documents.review.filter_suggestions' },
    { value: 'readings', label: 'documents.review.filter_readings' },
    { value: 'duplicates', label: 'documents.review.filter_duplicates' },
];

/**
 * Which bulk answers a filter offers.
 *
 * Filtering first is what makes the action unambiguous: offering "dismiss
 * duplicates" while somebody is looking at readings is an invitation to
 * answer for rows they are not reading.
 */
const ACTIONS: Record<Filter, BulkAction[]> = {
    all: ['accept_suggestions', 'dismiss_readings', 'dismiss_duplicates'],
    suggestions: ['accept_suggestions'],
    readings: ['dismiss_readings'],
    duplicates: ['dismiss_duplicates'],
};

const ACTION_LABELS: Record<BulkAction, TranslationKey> = {
    accept_suggestions: 'documents.review.bulk_accept_suggestions',
    dismiss_readings: 'documents.review.bulk_dismiss_readings',
    dismiss_duplicates: 'documents.review.bulk_dismiss_duplicates',
};

const ACTION_CONFIRMATIONS: Record<BulkAction, TranslationKey> = {
    accept_suggestions: 'documents.review.confirm_accept_suggestions',
    dismiss_readings: 'documents.review.confirm_dismiss_readings',
    dismiss_duplicates: 'documents.review.confirm_dismiss_duplicates',
};

export default function DocumentReview({
    workspaceId,
    filter,
    sort,
    documents,
    counts,
    pagination,
    labels,
}: Props) {
    const t = useTranslation();
    const [selected, setSelected] = useState<string[]>([]);
    // Set when somebody extends the selection past the page they can see, so
    // the answer is resolved from the filter on the server rather than from a
    // list of four thousand ids in a request body.
    const [allMatching, setAllMatching] = useState(false);

    const sorting = tableSort(
        documentsReview.url(workspaceId, { query: { filter } }),
        sort,
        [
            { key: 'title', label: t('documents.review.sort_title') },
            {
                key: 'updated_at',
                label: t('documents.review.sort_updated'),
                descendingFirst: true,
            },
            {
                key: 'waiting',
                label: t('documents.review.sort_waiting'),
                descendingFirst: true,
            },
        ],
    );

    const clearSelection = () => {
        setSelected([]);
        setAllMatching(false);
    };

    const toggleDocument = (id: string, checked: boolean) => {
        setAllMatching(false);
        setSelected((current) =>
            checked ? [...current, id] : current.filter((it) => it !== id),
        );
    };

    const togglePage = (checked: boolean) => {
        setAllMatching(false);
        setSelected(checked ? documents.map((document) => document.id) : []);
    };

    const answer = (action: BulkAction) => {
        if (!window.confirm(t(ACTION_CONFIRMATIONS[action]))) {
            return;
        }

        router.post(
            reviewBulk.url(workspaceId),
            {
                action,
                filter,
                // Empty means "everything this filter matches", which is what
                // the server resolves for itself.
                documents: allMatching ? [] : selected,
            },
            { preserveScroll: true, onSuccess: clearSelection },
        );
    };

    const answerLabel = (
        label: CandidateLabel,
        status: 'accepted' | 'rejected',
    ) => {
        router.patch(
            updateIntakeLabel.url([workspaceId, label.id]),
            { status },
            { preserveScroll: true },
        );
    };

    setLayoutProps({
        breadcrumbs: [
            {
                title: t('documents.review.breadcrumb_documents'),
                href: documentsIndex.url(workspaceId),
            },
            { title: t('documents.review.page_title'), href: '#' },
        ],
    });

    const selectedCount = allMatching ? pagination.total : selected.length;
    const pageFullySelected =
        documents.length > 0 && selected.length === documents.length;
    const morePastThisPage = pagination.total > documents.length;

    return (
        <>
            <Head title={t('documents.review.page_title')} />

            <PageContainer>
                <PageHeader
                    title={t('documents.review.page_title')}
                    description={t('documents.review.description')}
                />

                {counts.all === 0 && labels.length === 0 ? (
                    <EmptyState
                        title={t('documents.review.empty_title')}
                        description={t('documents.review.empty_description')}
                    />
                ) : (
                    <div className="space-y-6">
                        {counts.all > 0 && (
                            <div className="space-y-3">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <ToggleGroup
                                        type="single"
                                        variant="outline"
                                        size="sm"
                                        value={filter}
                                        onValueChange={(value) => {
                                            if (value === '') {
                                                return;
                                            }

                                            clearSelection();
                                            router.get(
                                                documentsReview.url(
                                                    workspaceId,
                                                    {
                                                        query: {
                                                            filter: value,
                                                        },
                                                    },
                                                ),
                                                {},
                                                { preserveScroll: true },
                                            );
                                        }}
                                    >
                                        {FILTERS.map((entry) => (
                                            <ToggleGroupItem
                                                key={entry.value}
                                                value={entry.value}
                                                disabled={
                                                    counts[entry.value] === 0
                                                }
                                            >
                                                {t(entry.label)}
                                                <span className="ml-1.5 text-xs text-muted-foreground">
                                                    {counts[entry.value]}
                                                </span>
                                            </ToggleGroupItem>
                                        ))}
                                    </ToggleGroup>
                                    <SortMenu sorting={sorting} />
                                </div>

                                <Panel>
                                    <PanelHeader>
                                        <label className="flex items-center gap-2 text-sm">
                                            <Checkbox
                                                checked={pageFullySelected}
                                                onCheckedChange={(value) =>
                                                    togglePage(value === true)
                                                }
                                                aria-label={t(
                                                    'documents.review.select_all',
                                                )}
                                            />
                                            <span className="text-muted-foreground">
                                                {selectedCount > 0
                                                    ? t(
                                                          'documents.review.selected_count',
                                                          {
                                                              count: selectedCount,
                                                          },
                                                      )
                                                    : t(
                                                          'documents.review.select_all',
                                                      )}
                                            </span>
                                        </label>

                                        {selected.length > 0 && (
                                            <div className="flex flex-wrap items-center gap-2">
                                                {ACTIONS[filter].map(
                                                    (action) => (
                                                        <Button
                                                            key={action}
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() =>
                                                                answer(action)
                                                            }
                                                        >
                                                            {t(
                                                                ACTION_LABELS[
                                                                    action
                                                                ],
                                                            )}
                                                        </Button>
                                                    ),
                                                )}
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={clearSelection}
                                                >
                                                    {t(
                                                        'documents.review.clear_selection',
                                                    )}
                                                </Button>
                                            </div>
                                        )}
                                    </PanelHeader>

                                    {pageFullySelected && morePastThisPage && (
                                        <div className="flex flex-wrap items-center gap-2 border-b bg-accent px-4 py-2 text-sm">
                                            <span className="min-w-0 flex-1">
                                                {allMatching
                                                    ? t(
                                                          'documents.review.all_matching_selected',
                                                          {
                                                              count: pagination.total,
                                                          },
                                                      )
                                                    : t(
                                                          'documents.review.page_selected',
                                                          {
                                                              count: documents.length,
                                                          },
                                                      )}
                                            </span>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                className="shrink-0"
                                                onClick={() =>
                                                    setAllMatching(!allMatching)
                                                }
                                            >
                                                {allMatching
                                                    ? t(
                                                          'documents.review.select_page_only',
                                                      )
                                                    : t(
                                                          'documents.review.select_all_matching',
                                                          {
                                                              count: pagination.total,
                                                          },
                                                      )}
                                            </Button>
                                        </div>
                                    )}

                                    {documents.map((document) => (
                                        <ReviewRow
                                            key={document.id}
                                            document={document}
                                            selected={
                                                allMatching ||
                                                selected.includes(document.id)
                                            }
                                            onSelectedChange={(checked) =>
                                                toggleDocument(
                                                    document.id,
                                                    checked,
                                                )
                                            }
                                            onDismissDuplicate={(
                                                attachmentId,
                                            ) =>
                                                router.delete(
                                                    AttachmentController.dismissDuplicate.url(
                                                        attachmentId,
                                                    ),
                                                    { preserveScroll: true },
                                                )
                                            }
                                            onOpenOriginal={(documentId) =>
                                                router.visit(
                                                    documentShow.url(
                                                        documentId,
                                                    ),
                                                )
                                            }
                                        />
                                    ))}
                                </Panel>

                                <Pagination
                                    prev={pagination.prev}
                                    next={pagination.next}
                                    links={pagination.links}
                                    from={pagination.from}
                                    to={pagination.to}
                                    total={pagination.total}
                                />
                            </div>
                        )}

                        {labels.length > 0 && (
                            <div className="space-y-3">
                                <Heading
                                    variant="small"
                                    title={t('documents.review.labels_title')}
                                />
                                <Panel>
                                    <PanelHeader>
                                        <span className="text-sm text-muted-foreground">
                                            {t(
                                                'documents.review.labels_description',
                                            )}
                                        </span>
                                    </PanelHeader>
                                    {labels.map((label) => (
                                        <div
                                            key={label.id}
                                            className="flex flex-wrap items-center gap-2 border-b p-4 last:border-b-0"
                                        >
                                            <div className="min-w-0 flex-1 basis-64">
                                                <div className="truncate text-sm font-medium">
                                                    {t(
                                                        'documents.review.label_reads_as',
                                                        {
                                                            label: label.label,
                                                            field: label.field,
                                                        },
                                                    )}
                                                </div>
                                                <div className="truncate text-xs text-muted-foreground">
                                                    {label.support === 1
                                                        ? t(
                                                              'documents.review.label_evidence_one',
                                                              {
                                                                  count: label.support,
                                                              },
                                                          )
                                                        : t(
                                                              'documents.review.label_evidence_other',
                                                              {
                                                                  count: label.support,
                                                              },
                                                          )}
                                                </div>
                                                {label.documents.length > 0 && (
                                                    <div className="mt-1 flex flex-wrap gap-x-3 gap-y-1">
                                                        {label.documents.map(
                                                            (document) => (
                                                                <Link
                                                                    key={
                                                                        document.id
                                                                    }
                                                                    href={documentShow.url(
                                                                        document.id,
                                                                    )}
                                                                    className="max-w-56 truncate text-xs text-muted-foreground underline underline-offset-2 hover:text-foreground"
                                                                >
                                                                    {
                                                                        document.title
                                                                    }
                                                                </Link>
                                                            ),
                                                        )}
                                                    </div>
                                                )}
                                            </div>
                                            <Button
                                                size="sm"
                                                className="shrink-0"
                                                onClick={() =>
                                                    answerLabel(
                                                        label,
                                                        'accepted',
                                                    )
                                                }
                                            >
                                                {t(
                                                    'documents.review.label_accept',
                                                )}
                                            </Button>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                className="shrink-0"
                                                onClick={() =>
                                                    answerLabel(
                                                        label,
                                                        'rejected',
                                                    )
                                                }
                                            >
                                                {t(
                                                    'documents.review.label_reject',
                                                )}
                                            </Button>
                                        </div>
                                    ))}
                                </Panel>
                            </div>
                        )}
                    </div>
                )}
            </PageContainer>
        </>
    );
}
