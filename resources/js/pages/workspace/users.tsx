import {
    Head,
    router,
    setLayoutProps,
    useForm,
    usePage,
} from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
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
import { show as workspaceShow } from '@/routes/workspaces';
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
    const { errors } = usePage().props;
    const [inviteOpen, setInviteOpen] = useState(false);
    const [removeTarget, setRemoveTarget] = useState<Member | null>(null);
    const form = useForm({ email: '', name: '', role: 'user' });

    setLayoutProps({
        breadcrumbs: [
            { title: workspace.name, href: workspaceShow.url(workspace.id) },
            { title: 'Users', href: index.url(workspace.id) },
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
            <Head title={`${workspace.name} — Users`} />

            <div className="mx-auto max-w-4xl space-y-6 p-6">
                <div className="flex items-end justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Users
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {members.length} member
                            {members.length === 1 ? '' : 's'}
                        </p>
                    </div>
                    <Dialog open={inviteOpen} onOpenChange={setInviteOpen}>
                        <DialogTrigger asChild>
                            <Button size="sm">Invite member</Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogTitle>Invite a member</DialogTitle>
                            <div className="space-y-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="email">Email</Label>
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
                                    <Label htmlFor="invite_name">Name</Label>
                                    <Input
                                        id="invite_name"
                                        placeholder="If they don't have an account yet"
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
                                    <Label htmlFor="invite_role">Role</Label>
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
                                                User
                                            </SelectItem>
                                            <SelectItem value="admin">
                                                Admin
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <InputError message={form.errors.role} />
                                </div>
                            </div>
                            <DialogFooter>
                                <DialogClose asChild>
                                    <Button variant="ghost">Cancel</Button>
                                </DialogClose>
                                <Button
                                    onClick={submitInvite}
                                    disabled={form.processing}
                                >
                                    Invite
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </div>

                {errors.role && (
                    <p className="text-sm text-destructive">{errors.role}</p>
                )}
                {errors.user && (
                    <p className="text-sm text-destructive">{errors.user}</p>
                )}

                <div className="overflow-hidden rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Member</TableHead>
                                <TableHead>Email</TableHead>
                                <TableHead>Role</TableHead>
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
                                                    User
                                                </SelectItem>
                                                <SelectItem value="admin">
                                                    Admin
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
                                            Remove
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </div>

            <Dialog
                open={removeTarget !== null}
                onOpenChange={(open) => !open && setRemoveTarget(null)}
            >
                <DialogContent>
                    <DialogTitle>Remove this member?</DialogTitle>
                    <p className="text-sm text-muted-foreground">
                        {removeTarget?.name} will lose access to this workspace.
                        This can be undone by inviting them again.
                    </p>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button variant="secondary">Cancel</Button>
                        </DialogClose>
                        <Button variant="destructive" onClick={confirmRemove}>
                            Remove member
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
