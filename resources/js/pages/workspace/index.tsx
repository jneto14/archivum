import { Head, router, setLayoutProps, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { PageContainer } from '@/components/page-container';
import { PageHeader } from '@/components/page-header';
import { Panel } from '@/components/panel';
import {
    SortableTableHead,
    SortMenu,
    tableSort,
} from '@/components/sortable-table';
import type { SortState } from '@/components/sortable-table';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useIsDemo } from '@/hooks/use-demo';
import { useTranslation } from '@/hooks/use-translation';
import workspaces, {
    index,
    store as storeWorkspace,
} from '@/routes/workspaces';
import { show as workspaceSettingsShow } from '@/routes/workspaces/settings';
import type { WorkspaceSummary } from '@/types';

type Props = {
    sort: SortState;
    workspaces: WorkspaceSummary[];
};

export default function WorkspaceIndex({
    sort,
    workspaces: allWorkspaces,
}: Props) {
    const t = useTranslation();
    const isDemo = useIsDemo();
    const [createOpen, setCreateOpen] = useState(false);
    const form = useForm({ name: '' });

    const sorting = tableSort(index.url(), sort, [
        { key: 'name', label: t('workspace.index.name_header') },
        {
            key: 'users_count',
            label: t('workspace.index.members_header'),
            descendingFirst: true,
        },
        {
            key: 'created_at',
            label: t('workspace.index.created_header'),
            descendingFirst: true,
        },
    ]);

    setLayoutProps({
        breadcrumbs: [
            {
                title: t('workspace.index.heading'),
                href: index.url(),
            },
        ],
    });

    const manage = (workspaceId: string) => {
        router.post(
            workspaces.switch.url(workspaceId),
            {},
            {
                onSuccess: () =>
                    router.visit(workspaceSettingsShow.url(workspaceId)),
            },
        );
    };

    const submitCreate = (event: FormEvent) => {
        event.preventDefault();

        form.post(storeWorkspace.url(), {
            preserveScroll: true,
            onSuccess: () => {
                setCreateOpen(false);
                form.reset();
            },
        });
    };

    return (
        <>
            <Head title={t('workspace.index.head_title')} />

            <PageContainer>
                <PageHeader
                    title={t('workspace.index.heading')}
                    description={t('workspace.index.description')}
                >
                    <SortMenu sorting={sorting} />
                    {/*
                     * Left out on a demo: the route refuses it, nothing
                     * bounds how many a visitor could create, and each one
                     * outlives them in the next visitor's switcher.
                     */}
                    {!isDemo && (
                        <Dialog
                            open={createOpen}
                            onOpenChange={(open) => {
                                setCreateOpen(open);

                                if (!open) {
                                    form.reset();
                                    form.clearErrors();
                                }
                            }}
                        >
                            <DialogTrigger asChild>
                                <Button size="sm">
                                    {t('workspace.index.create_button')}
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogTitle>
                                    {t('workspace.index.create_dialog_title')}
                                </DialogTitle>
                                <form
                                    onSubmit={submitCreate}
                                    className="space-y-4"
                                >
                                    <div className="grid gap-2">
                                        <Label htmlFor="name">
                                            {t('workspace.index.name_label')}
                                        </Label>
                                        <Input
                                            id="name"
                                            value={form.data.name}
                                            onChange={(event) =>
                                                form.setData(
                                                    'name',
                                                    event.target.value,
                                                )
                                            }
                                            required
                                        />
                                        <InputError
                                            message={form.errors.name}
                                        />
                                    </div>
                                    <DialogFooter>
                                        <DialogClose asChild>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                            >
                                                {t(
                                                    'workspace.index.cancel_button',
                                                )}
                                            </Button>
                                        </DialogClose>
                                        <Button
                                            type="submit"
                                            disabled={form.processing}
                                        >
                                            {t('workspace.index.save_button')}
                                        </Button>
                                    </DialogFooter>
                                </form>
                            </DialogContent>
                        </Dialog>
                    )}
                </PageHeader>

                <Panel>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <SortableTableHead
                                    sortKey="name"
                                    sorting={sorting}
                                >
                                    {t('workspace.index.name_header')}
                                </SortableTableHead>
                                <SortableTableHead
                                    sortKey="users_count"
                                    sorting={sorting}
                                >
                                    {t('workspace.index.members_header')}
                                </SortableTableHead>
                                <SortableTableHead
                                    sortKey="created_at"
                                    sorting={sorting}
                                >
                                    {t('workspace.index.created_header')}
                                </SortableTableHead>
                                <TableHead className="w-24" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {allWorkspaces.map((workspace) => (
                                <TableRow key={workspace.id}>
                                    <TableCell className="font-medium">
                                        {workspace.name}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {workspace.usersCount === 1
                                            ? t(
                                                  'workspace.index.members_count_one',
                                                  {
                                                      count: workspace.usersCount,
                                                  },
                                              )
                                            : t(
                                                  'workspace.index.members_count_other',
                                                  {
                                                      count: workspace.usersCount,
                                                  },
                                              )}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {workspace.createdAtDiff}
                                    </TableCell>
                                    <TableCell>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => manage(workspace.id)}
                                        >
                                            {t('workspace.index.manage_button')}
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Panel>
            </PageContainer>
        </>
    );
}
