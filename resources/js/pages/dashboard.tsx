import { Head, Link, setLayoutProps } from '@inertiajs/react';
import {
    FileStackIcon,
    FileTextIcon,
    HardDriveIcon,
    UsersIcon,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { PageContainer } from '@/components/page-container';
import { PageHeader } from '@/components/page-header';
import { Panel, PanelHeader } from '@/components/panel';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useDateFormatter } from '@/hooks/use-date-formatter';
import { useTranslation } from '@/hooks/use-translation';
import { formatBytes } from '@/lib/utils';
import { dashboard } from '@/routes';
import {
    create as documentCreate,
    index as documentsIndex,
    show as documentShow,
} from '@/routes/documents';
import { index as workspacesIndex } from '@/routes/workspaces';
import { index as activityIndex } from '@/routes/workspaces/activity';

type RecentDocument = {
    id: string;
    title: string;
    document_type: string | null;
    updated_at: string | null;
};

type RecentActivity = {
    id: number;
    label: string | null;
    event: string | null;
    created_at: string | null;
};

type Props = {
    workspace: { id: string; name: string } | null;
    stats: {
        documents: number;
        users: number;
        attachments: number;
        storage_bytes: number;
    } | null;
    recentDocuments: RecentDocument[];
    recentActivity: RecentActivity[];
};

export default function Dashboard({
    workspace,
    stats,
    recentDocuments,
    recentActivity,
}: Props) {
    const t = useTranslation();
    const { formatDate, formatDateTime } = useDateFormatter();

    setLayoutProps({
        breadcrumbs: [{ title: t('dashboard.head_title'), href: dashboard() }],
    });

    if (workspace === null || stats === null) {
        return (
            <>
                <Head title={t('dashboard.head_title')} />

                <PageContainer>
                    <PageHeader title={t('dashboard.head_title')} />
                    <EmptyState
                        title={t('dashboard.no_workspace_title')}
                        description={t('dashboard.no_workspace_description')}
                    >
                        <Button asChild size="sm">
                            <Link href={workspacesIndex()}>
                                {t('dashboard.no_workspace_action')}
                            </Link>
                        </Button>
                    </EmptyState>
                </PageContainer>
            </>
        );
    }

    const tiles: { label: string; value: string; icon: LucideIcon }[] = [
        {
            label: t('dashboard.stat_documents'),
            value: String(stats.documents),
            icon: FileTextIcon,
        },
        {
            label: t('dashboard.stat_attachments'),
            value: String(stats.attachments),
            icon: FileStackIcon,
        },
        {
            label: t('dashboard.stat_users'),
            value: String(stats.users),
            icon: UsersIcon,
        },
        {
            label: t('dashboard.stat_storage'),
            value: formatBytes(stats.storage_bytes),
            icon: HardDriveIcon,
        },
    ];

    return (
        <>
            <Head title={t('dashboard.head_title')} />

            <PageContainer>
                <PageHeader
                    title={t('dashboard.head_title')}
                    description={t('dashboard.description', {
                        workspace: workspace.name,
                    })}
                >
                    <Button asChild size="sm">
                        <Link href={documentCreate.url(workspace.id)}>
                            {t('dashboard.new_document')}
                        </Link>
                    </Button>
                </PageHeader>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {tiles.map((tile) => (
                        <div
                            key={tile.label}
                            className="rounded-xl border bg-card p-4"
                        >
                            <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                <tile.icon className="size-4" />
                                {tile.label}
                            </div>
                            <div className="mt-2 text-2xl font-semibold tracking-tight">
                                {tile.value}
                            </div>
                        </div>
                    ))}
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Panel>
                        <PanelHeader>
                            <span className="text-sm font-medium">
                                {t('dashboard.recent_documents_title')}
                            </span>
                            <Button asChild variant="ghost" size="sm">
                                <Link href={documentsIndex.url(workspace.id)}>
                                    {t('dashboard.view_all')}
                                </Link>
                            </Button>
                        </PanelHeader>
                        {recentDocuments.length === 0 ? (
                            <p className="p-6 text-center text-sm text-muted-foreground">
                                {t('dashboard.recent_documents_empty')}
                            </p>
                        ) : (
                            recentDocuments.map((doc) => (
                                <Link
                                    key={doc.id}
                                    href={documentShow.url(doc.id)}
                                    className="flex items-center gap-3 border-b px-4 py-3 last:border-b-0 hover:bg-accent"
                                >
                                    <FileTextIcon className="size-4 shrink-0 text-muted-foreground" />
                                    <span className="min-w-0 flex-1 truncate text-sm font-medium">
                                        {doc.title}
                                    </span>
                                    {doc.document_type && (
                                        <Badge
                                            variant="secondary"
                                            className="shrink-0"
                                        >
                                            {doc.document_type}
                                        </Badge>
                                    )}
                                    <span className="shrink-0 text-xs text-muted-foreground">
                                        {doc.updated_at
                                            ? formatDate(doc.updated_at)
                                            : '—'}
                                    </span>
                                </Link>
                            ))
                        )}
                    </Panel>

                    <Panel>
                        <PanelHeader>
                            <span className="text-sm font-medium">
                                {t('dashboard.recent_activity_title')}
                            </span>
                            <Button asChild variant="ghost" size="sm">
                                <Link href={activityIndex.url(workspace.id)}>
                                    {t('dashboard.view_all')}
                                </Link>
                            </Button>
                        </PanelHeader>
                        {recentActivity.length === 0 ? (
                            <p className="p-6 text-center text-sm text-muted-foreground">
                                {t('dashboard.recent_activity_empty')}
                            </p>
                        ) : (
                            recentActivity.map((entry) => (
                                <div
                                    key={entry.id}
                                    className="flex items-center gap-3 border-b px-4 py-3 last:border-b-0"
                                >
                                    <span className="min-w-0 flex-1 truncate text-sm">
                                        {entry.label ?? entry.event ?? '—'}
                                    </span>
                                    <span className="shrink-0 text-xs text-muted-foreground">
                                        {entry.created_at
                                            ? formatDateTime(entry.created_at)
                                            : '—'}
                                    </span>
                                </div>
                            ))
                        )}
                    </Panel>
                </div>
            </PageContainer>
        </>
    );
}
