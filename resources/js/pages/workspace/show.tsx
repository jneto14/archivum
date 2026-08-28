import { Head, router, setLayoutProps, useForm } from '@inertiajs/react';
import { UsersIcon } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import { Progress } from '@/components/ui/progress';
import { useTranslation } from '@/hooks/use-translation';
import {
    show as workspaceShow,
    update as updateWorkspace,
} from '@/routes/workspaces';
import { index as usersIndex } from '@/routes/workspaces/users';

function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    const units = ['KB', 'MB', 'GB'];
    let value = bytes / 1024;
    let unitIndex = 0;

    while (value >= 1024 && unitIndex < units.length - 1) {
        value /= 1024;
        unitIndex++;
    }

    return `${value.toFixed(1)} ${units[unitIndex]}`;
}

type UsageFigure = { used: number; limit: number | null };

type Props = {
    workspace: { id: string; name: string };
    isAdmin: boolean;
    usage: {
        storage: UsageFigure;
        users: UsageFigure;
        documents: UsageFigure;
        attachments: UsageFigure;
    } | null;
};

export default function WorkspaceShow({ workspace, isAdmin, usage }: Props) {
    const t = useTranslation();
    const [renameOpen, setRenameOpen] = useState(false);
    const form = useForm({ name: workspace.name });

    setLayoutProps({
        breadcrumbs: [
            { title: workspace.name, href: workspaceShow.url(workspace.id) },
        ],
    });

    const submitRename = (event: FormEvent) => {
        event.preventDefault();

        form.patch(updateWorkspace.url(workspace.id), {
            preserveScroll: true,
            onSuccess: () => setRenameOpen(false),
        });
    };

    const usageCards: {
        key: keyof NonNullable<Props['usage']>;
        label: string;
        format: (n: number) => string;
    }[] = [
        {
            key: 'storage',
            label: t('workspace.show.usage_storage_label'),
            format: formatBytes,
        },
        {
            key: 'documents',
            label: t('workspace.show.usage_documents_label'),
            format: (n) => `${n}`,
        },
        {
            key: 'users',
            label: t('workspace.show.usage_users_label'),
            format: (n) => `${n}`,
        },
        {
            key: 'attachments',
            label: t('workspace.show.usage_attachments_label'),
            format: (n) => `${n}`,
        },
    ];

    return (
        <>
            <Head title={workspace.name} />

            <div className="mx-auto max-w-4xl space-y-6 p-6">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {workspace.name}
                        </h1>
                    </div>
                    {isAdmin && (
                        <Dialog open={renameOpen} onOpenChange={setRenameOpen}>
                            <DialogTrigger asChild>
                                <Button variant="outline" size="sm">
                                    {t('workspace.show.rename_button')}
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogTitle>
                                    {t('workspace.show.rename_dialog_title')}
                                </DialogTitle>
                                <form
                                    onSubmit={submitRename}
                                    className="space-y-4"
                                >
                                    <div className="grid gap-2">
                                        <Label htmlFor="name">
                                            {t('workspace.show.name_label')}
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
                                                    'workspace.show.cancel_button',
                                                )}
                                            </Button>
                                        </DialogClose>
                                        <Button
                                            type="submit"
                                            disabled={form.processing}
                                        >
                                            {t('workspace.show.save_button')}
                                        </Button>
                                    </DialogFooter>
                                </form>
                            </DialogContent>
                        </Dialog>
                    )}
                </div>

                <button
                    type="button"
                    onClick={() => router.visit(usersIndex.url(workspace.id))}
                    className="flex items-center gap-3 rounded-xl border bg-card p-4 text-left shadow-sm hover:bg-muted"
                >
                    <UsersIcon className="size-5 text-muted-foreground" />
                    <div>
                        <div className="font-semibold">
                            {t('workspace.show.users_nav_title')}
                        </div>
                        <div className="text-xs text-muted-foreground">
                            {t('workspace.show.users_nav_description')}
                        </div>
                    </div>
                </button>

                {usage && (
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {t('workspace.show.usage_card_title')}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            {usageCards.map(({ key, label, format }) => {
                                const figure = usage[key];
                                const pct =
                                    figure.limit !== null
                                        ? Math.round(
                                              (figure.used / figure.limit) *
                                                  100,
                                          )
                                        : null;

                                return (
                                    <div
                                        key={key}
                                        className="space-y-1.5 rounded-md border p-3"
                                    >
                                        <div className="flex items-center justify-between text-sm">
                                            <span className="font-medium">
                                                {label}
                                            </span>
                                            <span className="text-muted-foreground">
                                                {format(figure.used)}
                                                {figure.limit !== null
                                                    ? ` / ${format(figure.limit)}`
                                                    : ''}
                                            </span>
                                        </div>
                                        {pct !== null && (
                                            <Progress value={pct} />
                                        )}
                                    </div>
                                );
                            })}
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}
