import { Head, router, setLayoutProps } from '@inertiajs/react';
import { EmptyState } from '@/components/empty-state';
import { PageContainer } from '@/components/page-container';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { Panel } from '@/components/panel';
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
import type { TranslationKey } from '@/lib/translations';
import { download, retry } from '@/routes/workspaces/tasks';

type TaskRow = {
    id: string;
    type: string;
    status: 'queued' | 'processing' | 'completed' | 'failed';
    triggered_by: string;
    /** What the task acted on — an attachment's filename, where it has one. */
    subject: string | null;
    result: { documents_count?: number; error?: string } | null;
    started_at: string | null;
    finished_at: string | null;
    created_at: string | null;
};

type Props = {
    workspace: { id: string; name: string };
    tasks: {
        data: TaskRow[];
        prev_page_url: string | null;
        next_page_url: string | null;
        from: number | null;
        to: number | null;
        total: number;
    };
};

const TYPE_LABELS: Record<string, TranslationKey> = {
    document_export: 'workspace.tasks.type_document_export',
    bulk_document_move: 'workspace.tasks.type_bulk_document_move',
    attachment_text_extraction:
        'workspace.tasks.type_attachment_text_extraction',
};

const STATUS_LABELS: Record<TaskRow['status'], TranslationKey> = {
    queued: 'workspace.tasks.status_queued',
    processing: 'workspace.tasks.status_processing',
    completed: 'workspace.tasks.status_completed',
    failed: 'workspace.tasks.status_failed',
};

const STATUS_VARIANTS: Record<
    TaskRow['status'],
    'secondary' | 'default' | 'outline' | 'destructive'
> = {
    queued: 'secondary',
    processing: 'default',
    completed: 'outline',
    failed: 'destructive',
};

export default function WorkspaceTasks({ workspace, tasks }: Props) {
    const t = useTranslation();
    const { formatDateTime } = useDateFormatter();

    setLayoutProps({
        breadcrumbs: [{ title: t('workspace.tasks.title'), href: '#' }],
    });

    const retryTask = (task: TaskRow) => {
        router.post(
            retry.url({ workspace: workspace.id, task: task.id }),
            {},
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title={t('workspace.tasks.title')} />

            <PageContainer>
                <PageHeader
                    title={t('workspace.tasks.title')}
                    description={t('workspace.tasks.description')}
                />

                {tasks.data.length === 0 ? (
                    <EmptyState
                        title={t('workspace.tasks.empty_title')}
                        description={t('workspace.tasks.empty_description')}
                    />
                ) : (
                    <Panel>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>
                                        {t('workspace.tasks.column_type')}
                                    </TableHead>
                                    <TableHead>
                                        {t('workspace.tasks.column_status')}
                                    </TableHead>
                                    <TableHead>
                                        {t(
                                            'workspace.tasks.column_triggered_by',
                                        )}
                                    </TableHead>
                                    <TableHead>
                                        {t('workspace.tasks.column_started')}
                                    </TableHead>
                                    <TableHead className="w-32" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {tasks.data.map((task) => (
                                    <TableRow key={task.id}>
                                        <TableCell className="font-medium">
                                            {t(
                                                TYPE_LABELS[task.type] ??
                                                    task.type,
                                            )}
                                            {task.subject && (
                                                <p className="max-w-xs truncate text-xs font-normal text-muted-foreground">
                                                    {task.subject}
                                                </p>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                variant={
                                                    STATUS_VARIANTS[task.status]
                                                }
                                            >
                                                {t(STATUS_LABELS[task.status])}
                                            </Badge>
                                            {task.status === 'failed' &&
                                                task.result?.error && (
                                                    <p className="mt-1 max-w-xs text-xs text-muted-foreground">
                                                        {task.result.error}
                                                    </p>
                                                )}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {task.triggered_by}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {task.created_at
                                                ? formatDateTime(
                                                      task.created_at,
                                                  )
                                                : '—'}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {task.status === 'completed' &&
                                                task.type ===
                                                    'document_export' && (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        asChild
                                                    >
                                                        <a
                                                            href={download.url({
                                                                workspace:
                                                                    workspace.id,
                                                                task: task.id,
                                                            })}
                                                        >
                                                            {t(
                                                                'workspace.tasks.download_button',
                                                            )}
                                                        </a>
                                                    </Button>
                                                )}
                                            {task.status === 'failed' && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        retryTask(task)
                                                    }
                                                >
                                                    {t(
                                                        'workspace.tasks.retry_button',
                                                    )}
                                                </Button>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </Panel>
                )}

                {tasks.data.length > 0 && (
                    <Pagination
                        prev={tasks.prev_page_url}
                        next={tasks.next_page_url}
                        from={tasks.from}
                        to={tasks.to}
                        total={tasks.total}
                    />
                )}
            </PageContainer>
        </>
    );
}
