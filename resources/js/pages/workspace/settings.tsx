import { Link, router, setLayoutProps, useForm } from '@inertiajs/react';
import { CopyIcon, PlusIcon, Trash2Icon } from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    create as schemeCreate,
    show as schemeShow,
} from '@/routes/organization/schemes';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import tokens from '@/routes/tokens';
import {
    destroy as destroyWorkspace,
    show as workspaceShow,
    update as updateWorkspace,
} from '@/routes/workspaces';

type Scheme = { id: string; name: string } | null;

type Token = {
    id: number;
    name: string;
    created_at_diff: string | null;
    last_used_at_diff: string | null;
};

type Props = {
    workspace: { id: string; name: string };
    scheme: Scheme;
    instance: {
        multi_workspace_enabled: boolean;
        attachments_disk: string;
    };
    tokens: Token[];
};

export default function WorkspaceSettings({
    workspace,
    scheme,
    instance,
    tokens: apiTokens,
}: Props) {
    const [renameOpen, setRenameOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [deleteConfirmName, setDeleteConfirmName] = useState('');
    const [revealedToken, setRevealedToken] = useState<string | null>(null);

    const renameForm = useForm({ name: workspace.name });
    const tokenForm = useForm({ name: '' });

    setLayoutProps({
        breadcrumbs: [
            { title: workspace.name, href: workspaceShow.url(workspace.id) },
            { title: 'Settings', href: '#' },
        ],
    });

    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash;
            const token = flash?.newApiToken as string | undefined;

            if (token) {
                setRevealedToken(token);
            }
        });
    }, []);

    const submitRename = (event: FormEvent) => {
        event.preventDefault();

        renameForm.patch(updateWorkspace.url(workspace.id), {
            preserveScroll: true,
            onSuccess: () => setRenameOpen(false),
        });
    };

    const submitCreateToken = (event: FormEvent) => {
        event.preventDefault();

        tokenForm.post(tokens.store.url(), {
            preserveScroll: true,
            onSuccess: () => tokenForm.reset(),
        });
    };

    const deleteToken = (token: Token) => {
        router.delete(tokens.destroy.url(token.id), { preserveScroll: true });
    };

    const submitDelete = (event: FormEvent) => {
        event.preventDefault();

        router.delete(destroyWorkspace.url(workspace.id));
    };

    return (
        <div className="mx-auto max-w-3xl space-y-6 p-6">
            <Heading
                title="Settings"
                description="Manage this workspace's configuration, API tokens, and account."
            />

            <Card>
                <CardHeader>
                    <CardTitle>Workspace</CardTitle>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="flex items-center justify-between gap-4">
                        <div>
                            <div className="text-sm font-medium">Name</div>
                            <div className="text-sm text-muted-foreground">
                                {workspace.name}
                            </div>
                        </div>
                        <Dialog open={renameOpen} onOpenChange={setRenameOpen}>
                            <DialogTrigger asChild>
                                <Button variant="outline" size="sm">
                                    Rename
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogTitle>Rename workspace</DialogTitle>
                                <form
                                    onSubmit={submitRename}
                                    className="space-y-4"
                                >
                                    <div className="grid gap-2">
                                        <Label htmlFor="name">Name</Label>
                                        <Input
                                            id="name"
                                            value={renameForm.data.name}
                                            onChange={(event) =>
                                                renameForm.setData(
                                                    'name',
                                                    event.target.value,
                                                )
                                            }
                                            required
                                        />
                                        <InputError
                                            message={renameForm.errors.name}
                                        />
                                    </div>
                                    <DialogFooter>
                                        <DialogClose asChild>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                            >
                                                Cancel
                                            </Button>
                                        </DialogClose>
                                        <Button
                                            type="submit"
                                            disabled={renameForm.processing}
                                        >
                                            Save
                                        </Button>
                                    </DialogFooter>
                                </form>
                            </DialogContent>
                        </Dialog>
                    </div>

                    <div className="flex items-center justify-between gap-4 border-t pt-4">
                        <div>
                            <div className="text-sm font-medium">
                                Organization scheme
                            </div>
                            <div className="text-sm text-muted-foreground">
                                {scheme ? scheme.name : 'No scheme created yet'}
                            </div>
                        </div>
                        <Button variant="outline" size="sm" asChild>
                            <Link
                                href={
                                    scheme
                                        ? schemeShow.url(scheme.id)
                                        : schemeCreate.url(workspace.id)
                                }
                            >
                                {scheme ? 'View' : 'Create'}
                            </Link>
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Instance</CardTitle>
                </CardHeader>
                <CardContent className="space-y-3">
                    <p className="text-xs text-muted-foreground">
                        Set via environment configuration — not editable here.
                    </p>
                    <div className="flex items-center justify-between">
                        <span className="text-sm">Multi-workspace</span>
                        <Badge
                            variant={
                                instance.multi_workspace_enabled
                                    ? 'default'
                                    : 'outline'
                            }
                        >
                            {instance.multi_workspace_enabled
                                ? 'Enabled'
                                : 'Disabled'}
                        </Badge>
                    </div>
                    <div className="flex items-center justify-between">
                        <span className="text-sm">Attachments disk</span>
                        <Badge variant="secondary">
                            {instance.attachments_disk}
                        </Badge>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>API tokens</CardTitle>
                </CardHeader>
                <CardContent className="space-y-4">
                    {apiTokens.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            No API tokens yet.
                        </p>
                    )}
                    {apiTokens.map((token) => (
                        <div
                            key={token.id}
                            className="flex items-center justify-between gap-3 rounded-md border p-3"
                        >
                            <div>
                                <div className="text-sm font-medium">
                                    {token.name}
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    Created {token.created_at_diff} ·{' '}
                                    {token.last_used_at_diff
                                        ? `Last used ${token.last_used_at_diff}`
                                        : 'Never used'}
                                </div>
                            </div>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() => deleteToken(token)}
                            >
                                <Trash2Icon />
                            </Button>
                        </div>
                    ))}

                    <form
                        onSubmit={submitCreateToken}
                        className="flex items-end gap-2 rounded-md border border-dashed p-3"
                    >
                        <div className="grid flex-1 gap-2">
                            <Label htmlFor="token_name">New token name</Label>
                            <Input
                                id="token_name"
                                placeholder="CLI access"
                                value={tokenForm.data.name}
                                onChange={(event) =>
                                    tokenForm.setData(
                                        'name',
                                        event.target.value,
                                    )
                                }
                            />
                            <InputError message={tokenForm.errors.name} />
                        </div>
                        <Button
                            type="submit"
                            size="sm"
                            disabled={tokenForm.processing}
                        >
                            <PlusIcon /> Create token
                        </Button>
                    </form>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Your account</CardTitle>
                </CardHeader>
                <CardContent className="flex gap-2">
                    <Button variant="outline" size="sm" asChild>
                        <Link href={editProfile()}>Profile</Link>
                    </Button>
                    <Button variant="outline" size="sm" asChild>
                        <Link href={editSecurity()}>Security</Link>
                    </Button>
                </CardContent>
            </Card>

            <Card className="border-red-100 dark:border-red-200/10">
                <CardHeader>
                    <CardTitle>Delete workspace</CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10">
                        <div className="space-y-0.5 text-red-600 dark:text-red-100">
                            <p className="font-medium">Warning</p>
                            <p className="text-sm">
                                Please proceed with caution, this cannot be
                                undone. All documents, attachments, and the
                                organization scheme will be permanently deleted.
                            </p>
                        </div>

                        <Dialog
                            open={deleteOpen}
                            onOpenChange={(open) => {
                                setDeleteOpen(open);

                                if (!open) {
                                    setDeleteConfirmName('');
                                }
                            }}
                        >
                            <DialogTrigger asChild>
                                <Button variant="destructive">
                                    Delete workspace
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogTitle>
                                    Are you sure you want to delete{' '}
                                    {workspace.name}?
                                </DialogTitle>
                                <DialogDescription>
                                    This will permanently delete the workspace
                                    and all of its data. Type{' '}
                                    <strong>{workspace.name}</strong> to
                                    confirm.
                                </DialogDescription>
                                <form
                                    onSubmit={submitDelete}
                                    className="space-y-4"
                                >
                                    <div className="grid gap-2">
                                        <Label
                                            htmlFor="confirm_name"
                                            className="sr-only"
                                        >
                                            Workspace name
                                        </Label>
                                        <Input
                                            id="confirm_name"
                                            value={deleteConfirmName}
                                            onChange={(event) =>
                                                setDeleteConfirmName(
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <DialogFooter className="gap-2">
                                        <DialogClose asChild>
                                            <Button variant="secondary">
                                                Cancel
                                            </Button>
                                        </DialogClose>
                                        <Button
                                            type="submit"
                                            variant="destructive"
                                            disabled={
                                                deleteConfirmName !==
                                                workspace.name
                                            }
                                        >
                                            Delete workspace
                                        </Button>
                                    </DialogFooter>
                                </form>
                            </DialogContent>
                        </Dialog>
                    </div>
                </CardContent>
            </Card>

            <Dialog
                open={revealedToken !== null}
                onOpenChange={(open) => !open && setRevealedToken(null)}
            >
                <DialogContent>
                    <DialogTitle>API token created</DialogTitle>
                    <DialogDescription>
                        Copy this token now — it won&apos;t be shown again.
                    </DialogDescription>
                    <div className="flex items-center gap-2">
                        <Input readOnly value={revealedToken ?? ''} />
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                revealedToken &&
                                navigator.clipboard.writeText(revealedToken)
                            }
                        >
                            <CopyIcon />
                        </Button>
                    </div>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button>Done</Button>
                        </DialogClose>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
