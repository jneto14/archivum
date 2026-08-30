import { Head, router, setLayoutProps } from '@inertiajs/react';
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
import { useTranslation } from '@/hooks/use-translation';
import type { TranslationKey } from '@/lib/translations';
import { download, retry, store } from '@/routes/workspaces/tasks';

type TaskRow = {
    id: string;
    type: string;
    status: 'queued' | 'processing' | 'completed' | 'failed';
    triggered_by: string;
    result: { documents_count?: number; error?: string } | null;
    started_at: string | null;
    finished_at: string | null;
    created_at: string | null;
};

type Props = {
    workspace: { id: string; name: string };
    tasks: TaskRow[];
};

const TYPE_LABELS: Record<string, TranslationKey> = {
    document_export: 'workspace.tasks.type_document_export',
    bulk_document_move: 'workspace.tasks.type_bulk_document_move',
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

    setLayoutProps({
        breadcrumbs: [{ title: t('workspace.tasks.title'), href: '#' }],
    });

    const startExport = () => {
        router.post(store.url(workspace.id), {}, { preserveScroll: true });
    };

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

            <div className="mx-auto max-w-4xl space-y-6 p-6">
                <div className="flex items-end justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {t('workspace.tasks.title')}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {t('workspace.tasks.description')}
                        </p>
                    </div>
                    <Button size="sm" onClick={startExport}>
                        {t('workspace.tasks.export_button')}
                    </Button>
                </div>

                {tasks.length === 0 ? (
                    <div className="rounded-xl border border-dashed p-12 text-center">
                        <div className="font-semibold">
                            {t('workspace.tasks.empty_title')}
                        </div>
                        <div className="text-sm text-muted-foreground">
                            {t('workspace.tasks.empty_description')}
                        </div>
                    </div>
                ) : (
                    <div className="overflow-hidden rounded-xl border">
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
                                {tasks.map((task) => (
                                    <TableRow key={task.id}>
                                        <TableCell className="font-medium">
                                            {t(
                                                TYPE_LABELS[task.type] ??
                                                    task.type,
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
                                                ? new Date(
                                                      task.created_at,
                                                  ).toLocaleString()
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
                    </div>
                )}
            </div>
        </>
    );
}
