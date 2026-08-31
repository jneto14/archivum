import { Head, setLayoutProps } from '@inertiajs/react';
import { PageContainer } from '@/components/page-container';
import { PageHeader } from '@/components/page-header';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import { useTranslation } from '@/hooks/use-translation';
import { formatBytes } from '@/lib/utils';

type Metric = {
    used: number;
    limit: number | null;
};

type Props = {
    workspace: { id: string; name: string };
    usage: {
        storage: Metric;
        users: Metric;
        documents: Metric;
        attachments: Metric;
    };
};

function percentage(metric: Metric): number | null {
    if (metric.limit === null || metric.limit === 0) {
        return null;
    }

    return Math.min(100, Math.round((metric.used / metric.limit) * 100));
}

export default function WorkspaceUsage({ workspace, usage }: Props) {
    const t = useTranslation();

    setLayoutProps({
        breadcrumbs: [{ title: t('workspace.usage.title'), href: '#' }],
    });

    const metrics: {
        key: keyof Props['usage'];
        label: string;
        format: (value: number) => string;
    }[] = [
        {
            key: 'storage',
            label: t('workspace.usage.storage_label'),
            format: formatBytes,
        },
        {
            key: 'users',
            label: t('workspace.usage.users_label'),
            format: (value) => String(value),
        },
        {
            key: 'documents',
            label: t('workspace.usage.documents_label'),
            format: (value) => String(value),
        },
        {
            key: 'attachments',
            label: t('workspace.usage.attachments_label'),
            format: (value) => String(value),
        },
    ];

    return (
        <>
            <Head title={t('workspace.usage.title')} />

            <PageContainer width="narrow">
                <PageHeader
                    title={t('workspace.usage.title')}
                    description={t('workspace.usage.description', {
                        workspace: workspace.name,
                    })}
                />

                <Card>
                    <CardHeader>
                        <CardTitle>{t('workspace.usage.card_title')}</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        {metrics.map(({ key, label, format }) => {
                            const metric = usage[key];
                            const pct = percentage(metric);

                            return (
                                <div key={key} className="space-y-1.5">
                                    <div className="flex items-center justify-between text-sm">
                                        <span className="font-medium">
                                            {label}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {metric.limit === null
                                                ? t(
                                                      'workspace.usage.unlimited',
                                                      {
                                                          used: format(
                                                              metric.used,
                                                          ),
                                                      },
                                                  )
                                                : t(
                                                      'workspace.usage.used_of_limit',
                                                      {
                                                          used: format(
                                                              metric.used,
                                                          ),
                                                          limit: format(
                                                              metric.limit,
                                                          ),
                                                      },
                                                  )}
                                        </span>
                                    </div>
                                    {pct !== null && <Progress value={pct} />}
                                </div>
                            );
                        })}
                    </CardContent>
                </Card>
            </PageContainer>
        </>
    );
}
