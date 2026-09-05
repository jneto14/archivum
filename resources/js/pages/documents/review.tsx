import { Head, Link, router, setLayoutProps } from '@inertiajs/react';
import AttachmentController from '@/actions/App/Http/Controllers/Documents/AttachmentController';
import { DocumentSuggestionReview } from '@/components/document-suggestion-review';
import type { ReviewableDocument } from '@/components/document-suggestion-review';
import { EmptyState } from '@/components/empty-state';
import Heading from '@/components/heading';
import { PageContainer } from '@/components/page-container';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import type { PageLink } from '@/components/pagination';
import { Panel, PanelHeader } from '@/components/panel';
import { SortMenu, tableSort } from '@/components/sortable-table';
import type { SortState } from '@/components/sortable-table';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import {
    index as documentsIndex,
    review as documentsReview,
    show as documentShow,
} from '@/routes/documents';
import { update as updateIntakeLabel } from '@/routes/workspaces/intake-labels';

type DuplicateRow = {
    id: string;
    filename: string;
    document_id: string;
    document_title: string;
    duplicate_of: {
        document_id: string;
        document_title: string | null;
    };
};

type CandidateLabel = {
    id: string;
    kind: string;
    field: string;
    label: string;
    support: number;
    documents: { id: string; title: string }[];
};

type Props = {
    workspaceId: string;
    sort: SortState;
    documents: ReviewableDocument[];
    pagination: {
        prev: string | null;
        next: string | null;
        links: PageLink[];
        from: number | null;
        to: number | null;
        total: number;
    };
    duplicates: DuplicateRow[];
    labels: CandidateLabel[];
};

export default function DocumentReview({
    workspaceId,
    sort,
    documents,
    pagination,
    duplicates,
    labels,
}: Props) {
    const t = useTranslation();

    // Only the queue of documents is ordered by this — the duplicates and the
    // candidate labels below it are their own lists — so the control sits with
    // that section rather than in the page header.
    const sorting = tableSort(documentsReview.url(workspaceId), sort, [
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
    ]);

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

    const nothingToReview =
        documents.length === 0 &&
        duplicates.length === 0 &&
        labels.length === 0;

    return (
        <>
            <Head title={t('documents.review.page_title')} />

            <PageContainer>
                <PageHeader
                    title={t('documents.review.page_title')}
                    description={t('documents.review.description')}
                />

                {nothingToReview ? (
                    <EmptyState
                        title={t('documents.review.empty_title')}
                        description={t('documents.review.empty_description')}
                    />
                ) : (
                    <div className="space-y-6">
                        {documents.length > 0 && (
                            <div className="space-y-3">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <Heading
                                        variant="small"
                                        title={t(
                                            'documents.review.suggestions_title',
                                        )}
                                    />
                                    <SortMenu sorting={sorting} />
                                </div>
                                <Panel>
                                    {documents.map((document) => (
                                        <DocumentSuggestionReview
                                            key={document.id}
                                            document={document}
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

                        {duplicates.length > 0 && (
                            <div className="space-y-3">
                                <Heading
                                    variant="small"
                                    title={t(
                                        'documents.review.duplicates_title',
                                    )}
                                />
                                <Panel>
                                    <PanelHeader>
                                        <span className="text-sm text-muted-foreground">
                                            {t(
                                                'documents.review.duplicates_description',
                                            )}
                                        </span>
                                    </PanelHeader>
                                    {duplicates.map((duplicate) => (
                                        <div
                                            key={duplicate.id}
                                            className="flex flex-wrap items-center gap-2 border-b p-4 last:border-b-0"
                                        >
                                            <div className="min-w-0 flex-1">
                                                <div className="truncate text-sm font-medium">
                                                    {duplicate.document_title}
                                                </div>
                                                <div className="truncate text-xs text-muted-foreground">
                                                    {t(
                                                        'documents.review.duplicate_of',
                                                        {
                                                            filename:
                                                                duplicate.filename,
                                                            document:
                                                                duplicate
                                                                    .duplicate_of
                                                                    .document_title ??
                                                                '—',
                                                        },
                                                    )}
                                                </div>
                                            </div>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                className="shrink-0"
                                                onClick={() =>
                                                    router.visit(
                                                        documentShow.url(
                                                            duplicate
                                                                .duplicate_of
                                                                .document_id,
                                                        ),
                                                    )
                                                }
                                            >
                                                {t(
                                                    'documents.review.open_original',
                                                )}
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                className="shrink-0"
                                                onClick={() =>
                                                    router.delete(
                                                        AttachmentController.dismissDuplicate.url(
                                                            duplicate.id,
                                                        ),
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                {t(
                                                    'documents.review.keep_both',
                                                )}
                                            </Button>
                                        </div>
                                    ))}
                                </Panel>
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
