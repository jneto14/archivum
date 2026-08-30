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
    };
};

const TYPE_LABELS: Record<string, TranslationKey> = {
    document: 'workspace.activity.type_document',
    document_type: 'workspace.activity.type_document_type',
    organization_scheme: 'workspace.activity.type_organization_scheme',
    organization_level: 'workspace.activity.type_organization_level',
    organization_rule: 'workspace.activity.type_organization_rule',
    workspace: 'workspace.activity.type_workspace',
    workspace_member: 'workspace.activity.type_workspace_member',
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

    setLayoutProps({
        breadcrumbs: [
            {
                title: t('workspace.activity.title'),
                href: activityIndex.url(workspace.id),
            },
        ],
    });

    const goTo = (url: string | null) => {
        if (url === null) {
            return;
        }

        router.visit(url, { preserveState: true, preserveScroll: true });
    };

    return (
        <>
            <Head title={t('workspace.activity.title')} />

            <div className="mx-auto max-w-4xl space-y-6 p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {t('workspace.activity.title')}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t('workspace.activity.description')}
                    </p>
                </div>

                {activities.data.length === 0 ? (
                    <div className="rounded-xl border border-dashed p-12 text-center">
                        <div className="font-semibold">
                            {t('workspace.activity.empty_title')}
                        </div>
                        <div className="text-sm text-muted-foreground">
                            {t('workspace.activity.empty_description')}
                        </div>
                    </div>
                ) : (
                    <>
                        <div className="overflow-hidden rounded-xl border">
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
                                                    ? new Date(
                                                          activity.created_at,
                                                      ).toLocaleString()
                                                    : '—'}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>

                        <div className="flex justify-end gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={activities.prev_page_url === null}
                                onClick={() =>
                                    goTo(activities.prev_page_url)
                                }
                            >
                                {t('workspace.activity.previous_page')}
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={activities.next_page_url === null}
                                onClick={() =>
                                    goTo(activities.next_page_url)
                                }
                            >
                                {t('workspace.activity.next_page')}
                            </Button>
                        </div>
                    </>
                )}
            </div>
        </>
    );
}
