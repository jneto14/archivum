import { Head, router, setLayoutProps } from '@inertiajs/react';
import { EmptyState } from '@/components/empty-state';
import { PageContainer } from '@/components/page-container';
import { PageHeader } from '@/components/page-header';
import { Panel, PanelHeader } from '@/components/panel';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useDateFormatter } from '@/hooks/use-date-formatter';
import { useTranslation } from '@/hooks/use-translation';
import { formatBytes } from '@/lib/utils';
import { empty } from '@/routes/trash';
import {
    purge as purgeAttachment,
    restore as restoreAttachment,
} from '@/routes/trash/attachments';
import {
    purge as purgeDocument,
    restore as restoreDocument,
} from '@/routes/trash/documents';

type TrashedDocument = {
    id: string;
    title: string;
    document_type: string | null;
    attachments_count: number;
    deleted_at: string | null;
};

type TrashedAttachment = {
    id: string;
    filename: string;
    size: number;
    document: { id: string; title: string } | null;
    deleted_at: string | null;
};

type Props = {
    workspace: { id: string; name: string };
    /** Days an item stays in the trash before the scheduled prune destroys it; 0 disables the sweep. */
    retentionDays: number;
    /** Whether the viewer administers the workspace. Purging is irreversible and admin-only. */
    canPurge: boolean;
    documents: { data: TrashedDocument[]; meta: { total: number } };
    attachments: { data: TrashedAttachment[]; meta: { total: number } };
};

export default function WorkspaceTrash({
    workspace,
    retentionDays,
    canPurge,
    documents,
    attachments,
}: Props) {
    const t = useTranslation();
    const { formatDateTime } = useDateFormatter();

    setLayoutProps({
        breadcrumbs: [{ title: t('workspace.trash.title'), href: '#' }],
    });

    const isEmpty =
        documents.data.length === 0 && attachments.data.length === 0;

    const confirmAnd = (message: string, run: () => void) => {
        if (window.confirm(message)) {
            run();
        }
    };

    return (
        <>
            <Head title={t('workspace.trash.title')} />

            <PageContainer>
                <PageHeader
                    title={t('workspace.trash.title')}
                    description={
                        retentionDays > 0
                            ? t('workspace.trash.description', {
                                  days: String(retentionDays),
                              })
                            : t('workspace.trash.description_no_retention')
                    }
                >
                    {canPurge && !isEmpty && (
                        <Button
                            variant="destructive"
                            size="sm"
                            onClick={() =>
                                confirmAnd(
                                    t('workspace.trash.confirm_empty'),
                                    () =>
                                        router.delete(
                                            empty.url(workspace.id),
                                            { preserveScroll: true },
                                        ),
                                )
                            }
                        >
                            {t('workspace.trash.empty_action')}
                        </Button>
                    )}
                </PageHeader>

                {isEmpty ? (
                    <EmptyState
                        title={t('workspace.trash.empty_title')}
                        description={t('workspace.trash.empty_description')}
                    />
                ) : (
                    <>
                        {documents.data.length > 0 && (
                            <Panel>
                                <PanelHeader>
                                    <span className="text-sm font-medium">
                                        {t(
                                            'workspace.trash.documents_heading',
                                        )}
                                    </span>
                                </PanelHeader>
                                <Table className="min-w-[40rem] table-fixed">
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead className="w-[44%]">
                                                {t(
                                                    'workspace.trash.column_document',
                                                )}
                                            </TableHead>
                                            <TableHead className="w-[20%]">
                                                {t(
                                                    'workspace.trash.column_type',
                                                )}
                                            </TableHead>
                                            <TableHead className="w-[20%]">
                                                {t(
                                                    'workspace.trash.column_deleted',
                                                )}
                                            </TableHead>
                                            <TableHead className="w-[16%]" />
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {documents.data.map((document) => (
                                            <TableRow key={document.id}>
                                                <TableCell className="whitespace-normal">
                                                    <span className="line-clamp-2 break-words">
                                                        {document.title}
                                                    </span>
                                                    {document.attachments_count >
                                                        0 && (
                                                        <Badge
                                                            variant="secondary"
                                                            className="mt-1"
                                                        >
                                                            {t(
                                                                'workspace.trash.attachments_count',
                                                                {
                                                                    count: String(
                                                                        document.attachments_count,
                                                                    ),
                                                                },
                                                            )}
                                                        </Badge>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    {document.document_type ??
                                                        '—'}
                                                </TableCell>
                                                <TableCell>
                                                    {document.deleted_at
                                                        ? formatDateTime(
                                                              document.deleted_at,
                                                          )
                                                        : '—'}
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex flex-wrap justify-end gap-2">
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            className="shrink-0"
                                                            onClick={() =>
                                                                router.post(
                                                                    restoreDocument.url(
                                                                        {
                                                                            workspace:
                                                                                workspace.id,
                                                                            document:
                                                                                document.id,
                                                                        },
                                                                    ),
                                                                    {},
                                                                    {
                                                                        preserveScroll:
                                                                            true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            {t(
                                                                'workspace.trash.restore',
                                                            )}
                                                        </Button>
                                                        {canPurge && (
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                className="shrink-0 text-destructive"
                                                                onClick={() =>
                                                                    confirmAnd(
                                                                        t(
                                                                            'workspace.trash.confirm_purge_document',
                                                                        ),
                                                                        () =>
                                                                            router.delete(
                                                                                purgeDocument.url(
                                                                                    {
                                                                                        workspace:
                                                                                            workspace.id,
                                                                                        document:
                                                                                            document.id,
                                                                                    },
                                                                                ),
                                                                                {
                                                                                    preserveScroll:
                                                                                        true,
                                                                                },
                                                                            ),
                                                                    )
                                                                }
                                                            >
                                                                {t(
                                                                    'workspace.trash.purge',
                                                                )}
                                                            </Button>
                                                        )}
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </Panel>
                        )}

                        {attachments.data.length > 0 && (
                            <Panel>
                                <PanelHeader className="flex-col items-start gap-0">
                                    <span className="text-sm font-medium">
                                        {t(
                                            'workspace.trash.attachments_heading',
                                        )}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {t(
                                            'workspace.trash.attachments_description',
                                        )}
                                    </span>
                                </PanelHeader>
                                <Table className="min-w-[40rem] table-fixed">
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead className="w-[36%]">
                                                {t(
                                                    'workspace.trash.column_file',
                                                )}
                                            </TableHead>
                                            <TableHead className="w-[28%]">
                                                {t(
                                                    'workspace.trash.column_document',
                                                )}
                                            </TableHead>
                                            <TableHead className="w-[20%]">
                                                {t(
                                                    'workspace.trash.column_deleted',
                                                )}
                                            </TableHead>
                                            <TableHead className="w-[16%]" />
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {attachments.data.map((attachment) => (
                                            <TableRow key={attachment.id}>
                                                <TableCell className="whitespace-normal">
                                                    <span className="line-clamp-2 break-words">
                                                        {attachment.filename}
                                                    </span>
                                                    <span className="block text-xs text-muted-foreground">
                                                        {formatBytes(
                                                            attachment.size,
                                                        )}
                                                    </span>
                                                </TableCell>
                                                <TableCell className="whitespace-normal">
                                                    <span className="line-clamp-2 break-words">
                                                        {attachment.document
                                                            ?.title ?? '—'}
                                                    </span>
                                                </TableCell>
                                                <TableCell>
                                                    {attachment.deleted_at
                                                        ? formatDateTime(
                                                              attachment.deleted_at,
                                                          )
                                                        : '—'}
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex flex-wrap justify-end gap-2">
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            className="shrink-0"
                                                            onClick={() =>
                                                                router.post(
                                                                    restoreAttachment.url(
                                                                        {
                                                                            workspace:
                                                                                workspace.id,
                                                                            attachment:
                                                                                attachment.id,
                                                                        },
                                                                    ),
                                                                    {},
                                                                    {
                                                                        preserveScroll:
                                                                            true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            {t(
                                                                'workspace.trash.restore',
                                                            )}
                                                        </Button>
                                                        {canPurge && (
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                className="shrink-0 text-destructive"
                                                                onClick={() =>
                                                                    confirmAnd(
                                                                        t(
                                                                            'workspace.trash.confirm_purge_attachment',
                                                                        ),
                                                                        () =>
                                                                            router.delete(
                                                                                purgeAttachment.url(
                                                                                    {
                                                                                        workspace:
                                                                                            workspace.id,
                                                                                        attachment:
                                                                                            attachment.id,
                                                                                    },
                                                                                ),
                                                                                {
                                                                                    preserveScroll:
                                                                                        true,
                                                                                },
                                                                            ),
                                                                    )
                                                                }
                                                            >
                                                                {t(
                                                                    'workspace.trash.purge',
                                                                )}
                                                            </Button>
                                                        )}
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </Panel>
                        )}
                    </>
                )}
            </PageContainer>
        </>
    );
}
