import { Head, router, setLayoutProps, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { PageContainer } from '@/components/page-container';
import { PageHeader } from '@/components/page-header';
import { Panel } from '@/components/panel';
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
import { useTranslation } from '@/hooks/use-translation';
import workspaces, {
    index,
    store as storeWorkspace,
} from '@/routes/workspaces';
import { show as workspaceSettingsShow } from '@/routes/workspaces/settings';
import type { WorkspaceSummary } from '@/types';

type Props = {
    workspaces: WorkspaceSummary[];
};

export default function WorkspaceIndex({ workspaces: allWorkspaces }: Props) {
    const t = useTranslation();
    const [createOpen, setCreateOpen] = useState(false);
    const form = useForm({ name: '' });

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
                            <form onSubmit={submitCreate} className="space-y-4">
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
                                    <InputError message={form.errors.name} />
                                </div>
                                <DialogFooter>
                                    <DialogClose asChild>
                                        <Button type="button" variant="ghost">
                                            {t('workspace.index.cancel_button')}
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
                </PageHeader>

                <Panel>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>
                                    {t('workspace.index.name_header')}
                                </TableHead>
                                <TableHead>
                                    {t('workspace.index.members_header')}
                                </TableHead>
                                <TableHead>
                                    {t('workspace.index.created_header')}
                                </TableHead>
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
