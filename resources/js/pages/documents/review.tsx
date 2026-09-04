import { Head, router, setLayoutProps } from '@inertiajs/react';
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
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import {
    index as documentsIndex,
    show as documentShow,
} from '@/routes/documents';

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

type Props = {
    workspaceId: string;
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
};

export default function DocumentReview({
    workspaceId,
    documents,
    pagination,
    duplicates,
}: Props) {
    const t = useTranslation();

    setLayoutProps({
        breadcrumbs: [
            {
                title: t('documents.review.breadcrumb_documents'),
                href: documentsIndex.url(workspaceId),
            },
            { title: t('documents.review.page_title'), href: '#' },
        ],
    });

    const nothingToReview = documents.length === 0 && duplicates.length === 0;

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
                                <Heading
                                    variant="small"
                                    title={t(
                                        'documents.review.suggestions_title',
                                    )}
                                />
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
                    </div>
                )}
            </PageContainer>
        </>
    );
}
