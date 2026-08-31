import { Head, setLayoutProps } from '@inertiajs/react';
import { EmptyState } from '@/components/empty-state';
import { PageContainer } from '@/components/page-container';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { Panel } from '@/components/panel';
import { Badge } from '@/components/ui/badge';
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
import { index as activityIndex } from '@/routes/workspaces/activity';

type ActivityRow = {
    id: number;
    log_name: string | null;
    event: string | null;
    label: string | null;
    causer: string | null;
    created_at: string | null;
};

type Props = {
    workspace: { id: string; name: string };
    activities: {
        data: ActivityRow[];
        prev_page_url: string | null;
        next_page_url: string | null;
        from: number | null;
        to: number | null;
        total: number;
    };
};

const TYPE_LABELS: Record<string, TranslationKey> = {
    document: 'workspace.activity.type_document',
    document_type: 'workspace.activity.type_document_type',
    document_attachment: 'workspace.activity.type_document_attachment',
    document_location: 'workspace.activity.type_document_location',
    organization_scheme: 'workspace.activity.type_organization_scheme',
    organization_level: 'workspace.activity.type_organization_level',
    organization_rule: 'workspace.activity.type_organization_rule',
    workspace: 'workspace.activity.type_workspace',
    workspace_member: 'workspace.activity.type_workspace_member',
    tag: 'workspace.activity.type_tag',
};

const EVENT_LABELS: Record<string, TranslationKey> = {
    created: 'workspace.activity.event_created',
    updated: 'workspace.activity.event_updated',
    deleted: 'workspace.activity.event_deleted',
    restored: 'workspace.activity.event_restored',
};

const EVENT_VARIANTS: Record<
    string,
    'secondary' | 'default' | 'outline' | 'destructive'
> = {
    created: 'default',
    updated: 'secondary',
    deleted: 'destructive',
    restored: 'outline',
};

export default function WorkspaceActivity({ workspace, activities }: Props) {
    const t = useTranslation();
    const { formatDateTime } = useDateFormatter();

    setLayoutProps({
        breadcrumbs: [
            {
                title: t('workspace.activity.title'),
                href: activityIndex.url(workspace.id),
            },
        ],
    });

    return (
        <>
            <Head title={t('workspace.activity.title')} />

            <PageContainer>
                <PageHeader
                    title={t('workspace.activity.title')}
                    description={t('workspace.activity.description')}
                />

                {activities.data.length === 0 ? (
                    <EmptyState
                        title={t('workspace.activity.empty_title')}
                        description={t('workspace.activity.empty_description')}
                    />
                ) : (
                    <>
                        <Panel>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>
                                            {t(
                                                'workspace.activity.column_type',
                                            )}
                                        </TableHead>
                                        <TableHead>
                                            {t(
                                                'workspace.activity.column_event',
                                            )}
                                        </TableHead>
                                        <TableHead>
                                            {t(
                                                'workspace.activity.column_label',
                                            )}
                                        </TableHead>
                                        <TableHead>
                                            {t(
                                                'workspace.activity.column_causer',
                                            )}
                                        </TableHead>
                                        <TableHead>
                                            {t(
                                                'workspace.activity.column_when',
                                            )}
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {activities.data.map((activity) => (
                                        <TableRow key={activity.id}>
                                            <TableCell className="font-medium">
                                                {activity.log_name &&
                                                TYPE_LABELS[activity.log_name]
                                                    ? t(
                                                          TYPE_LABELS[
                                                              activity.log_name
                                                          ],
                                                      )
                                                    : (activity.log_name ??
                                                      '—')}
                                            </TableCell>
                                            <TableCell>
                                                {activity.event &&
                                                EVENT_LABELS[activity.event] ? (
                                                    <Badge
                                                        variant={
                                                            EVENT_VARIANTS[
                                                                activity.event
                                                            ]
                                                        }
                                                    >
                                                        {t(
                                                            EVENT_LABELS[
                                                                activity.event
                                                            ],
                                                        )}
                                                    </Badge>
                                                ) : (
                                                    (activity.event ?? '—')
                                                )}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {activity.label ?? '—'}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {activity.causer ??
                                                    t(
                                                        'workspace.activity.system_causer',
                                                    )}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {activity.created_at
                                                    ? formatDateTime(
                                                          activity.created_at,
                                                      )
                                                    : '—'}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </Panel>

                        <Pagination
                            prev={activities.prev_page_url}
                            next={activities.next_page_url}
                            from={activities.from}
                            to={activities.to}
                            total={activities.total}
                        />
                    </>
                )}
            </PageContainer>
        </>
    );
}
