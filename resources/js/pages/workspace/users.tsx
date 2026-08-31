import {
    Head,
    router,
    setLayoutProps,
    useForm,
    usePage,
} from '@inertiajs/react';
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
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useTranslation } from '@/hooks/use-translation';
import { destroy, index, store, update } from '@/routes/workspaces/users';

type Member = {
    id: string;
    name: string;
    email: string;
    role: string;
};

type Props = {
    workspace: { id: string; name: string };
    members: Member[];
};

export default function WorkspaceUsers({ workspace, members }: Props) {
    const t = useTranslation();
    const { errors } = usePage().props;
    const [inviteOpen, setInviteOpen] = useState(false);
    const [removeTarget, setRemoveTarget] = useState<Member | null>(null);
    const form = useForm({ email: '', name: '', role: 'user' });

    setLayoutProps({
        breadcrumbs: [
            {
                title: t('workspace.users.heading'),
                href: index.url(workspace.id),
            },
        ],
    });

    const submitInvite = () => {
        form.post(store.url(workspace.id), {
            preserveScroll: true,
            onSuccess: () => {
                setInviteOpen(false);
                form.reset();
            },
        });
    };

    const changeRole = (member: Member, role: string) => {
        router.patch(
            update.url({ workspace: workspace.id, targetUser: member.id }),
            { role },
            { preserveScroll: true },
        );
    };

    const confirmRemove = () => {
        if (removeTarget === null) {
            return;
        }

        router.delete(
            destroy.url({
                workspace: workspace.id,
                targetUser: removeTarget.id,
            }),
            { preserveScroll: true, onFinish: () => setRemoveTarget(null) },
        );
    };

    return (
        <>
            <Head
                title={t('workspace.users.head_title', {
                    workspace: workspace.name,
                })}
            />

            <PageContainer>
                <PageHeader
                    title={t('workspace.users.heading')}
                    description={
                        members.length === 1
                            ? t('workspace.users.member_count_one', {
                                  count: members.length,
                              })
                            : t('workspace.users.member_count_other', {
                                  count: members.length,
                              })
                    }
                >
                    <Dialog open={inviteOpen} onOpenChange={setInviteOpen}>
                        <DialogTrigger asChild>
                            <Button size="sm">
                                {t('workspace.users.invite_button')}
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogTitle>
                                {t('workspace.users.invite_dialog_title')}
                            </DialogTitle>
                            <div className="space-y-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="email">
                                        {t('workspace.users.email_label')}
                                    </Label>
                                    <Input
                                        id="email"
                                        type="email"
                                        value={form.data.email}
                                        onChange={(event) =>
                                            form.setData(
                                                'email',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <InputError message={form.errors.email} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="invite_name">
                                        {t('workspace.users.name_label')}
                                    </Label>
                                    <Input
                                        id="invite_name"
                                        placeholder={t(
                                            'workspace.users.name_placeholder',
                                        )}
                                        value={form.data.name}
                                        onChange={(event) =>
                                            form.setData(
                                                'name',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <InputError message={form.errors.name} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="invite_role">
                                        {t('workspace.users.role_label')}
                                    </Label>
                                    <Select
                                        value={form.data.role}
                                        onValueChange={(value) =>
                                            form.setData('role', value)
                                        }
                                    >
                                        <SelectTrigger
                                            id="invite_role"
                                            className="w-full"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="user">
                                                {t('workspace.users.role_user')}
                                            </SelectItem>
                                            <SelectItem value="admin">
                                                {t(
                                                    'workspace.users.role_admin',
                                                )}
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <InputError message={form.errors.role} />
                                </div>
                            </div>
                            <DialogFooter>
                                <DialogClose asChild>
                                    <Button variant="ghost">
                                        {t('workspace.users.cancel_button')}
                                    </Button>
                                </DialogClose>
                                <Button
                                    onClick={submitInvite}
                                    disabled={form.processing}
                                >
                                    {t('workspace.users.invite_submit')}
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </PageHeader>

                {errors.role && (
                    <p className="text-sm text-destructive">{errors.role}</p>
                )}
                {errors.user && (
                    <p className="text-sm text-destructive">{errors.user}</p>
                )}

                <Panel>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>
                                    {t('workspace.users.member_header')}
                                </TableHead>
                                <TableHead>
                                    {t('workspace.users.email_label')}
                                </TableHead>
                                <TableHead>
                                    {t('workspace.users.role_label')}
                                </TableHead>
                                <TableHead className="w-9" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {members.map((member) => (
                                <TableRow key={member.id}>
                                    <TableCell className="font-medium">
                                        {member.name}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {member.email}
                                    </TableCell>
                                    <TableCell>
                                        <Select
                                            value={member.role}
                                            onValueChange={(value) =>
                                                changeRole(member, value)
                                            }
                                        >
                                            <SelectTrigger className="w-32">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="user">
                                                    {t(
                                                        'workspace.users.role_user',
                                                    )}
                                                </SelectItem>
                                                <SelectItem value="admin">
                                                    {t(
                                                        'workspace.users.role_admin',
                                                    )}
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </TableCell>
                                    <TableCell>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                setRemoveTarget(member)
                                            }
                                        >
                                            {t('workspace.users.remove_button')}
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Panel>
            </PageContainer>

            <Dialog
                open={removeTarget !== null}
                onOpenChange={(open) => !open && setRemoveTarget(null)}
            >
                <DialogContent>
                    <DialogTitle>
                        {t('workspace.users.remove_dialog_title')}
                    </DialogTitle>
                    <p className="text-sm text-muted-foreground">
                        {t('workspace.users.remove_dialog_description', {
                            name: removeTarget?.name ?? '',
                        })}
                    </p>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button variant="secondary">
                                {t('workspace.users.cancel_button')}
                            </Button>
                        </DialogClose>
                        <Button variant="destructive" onClick={confirmRemove}>
                            {t('workspace.users.remove_confirm_button')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
