import { Link, router, setLayoutProps, useForm } from '@inertiajs/react';
import { CheckIcon, CopyIcon, PlusIcon, Trash2Icon } from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
import { PageContainer } from '@/components/page-container';
import { PageHeader } from '@/components/page-header';
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
import { useClipboard } from '@/hooks/use-clipboard';
import { useIsDemo } from '@/hooks/use-demo';
import { useTranslation } from '@/hooks/use-translation';
import {
    create as schemeCreate,
    show as schemeShow,
} from '@/routes/organization/schemes';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import tokens from '@/routes/tokens';
import {
    destroy as destroyWorkspace,
    update as updateWorkspace,
} from '@/routes/workspaces';
import { update as updateIntakeLabel } from '@/routes/workspaces/intake-labels';
import { update as updateWorkspaceLimits } from '@/routes/workspaces/limits';

type Scheme = { id: string; name: string } | null;

type Token = {
    id: number;
    name: string;
    created_at_diff: string | null;
    last_used_at_diff: string | null;
};

type WorkspaceLimits = {
    storage_bytes: number | null;
    users: number | null;
    documents: number | null;
    attachments: number | null;
};

type Props = {
    workspace: { id: string; name: string };
    scheme: Scheme;
    instance: {
        multi_workspace_enabled: boolean;
        attachments_disk: string;
    };
    tokens: Token[];
    intakeLabels: { pending: IntakeLabel[]; accepted: IntakeLabel[] };
    isPlatformAdmin: boolean;
    limits: WorkspaceLimits | null;
};

/** A phrase this archive was seen writing in front of a value. */
type IntakeLabel = {
    id: string;
    kind: string;
    label: string;
    support: number;
};

const BYTES_PER_MB = 1024 * 1024;

export default function WorkspaceSettings({
    workspace,
    scheme,
    instance,
    tokens: apiTokens,
    intakeLabels,
    isPlatformAdmin,
    limits,
}: Props) {
    const t = useTranslation();
    const isDemo = useIsDemo();
    const [copiedText, copy] = useClipboard();

    const [renameOpen, setRenameOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [deleteConfirmName, setDeleteConfirmName] = useState('');
    const [revealedToken, setRevealedToken] = useState<string | null>(null);

    const renameForm = useForm({ name: workspace.name });
    const tokenForm = useForm({ name: '' });
    const limitsForm = useForm({
        storage_mb:
            limits?.storage_bytes != null
                ? String(Math.round(limits.storage_bytes / BYTES_PER_MB))
                : '',
        users: limits?.users != null ? String(limits.users) : '',
        documents: limits?.documents != null ? String(limits.documents) : '',
        attachments:
            limits?.attachments != null ? String(limits.attachments) : '',
    });

    setLayoutProps({
        breadcrumbs: [{ title: t('workspace.settings.title'), href: '#' }],
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

    const submitLimits = (event: FormEvent) => {
        event.preventDefault();

        limitsForm.transform((data) => ({
            storage_bytes:
                data.storage_mb === ''
                    ? null
                    : Number(data.storage_mb) * BYTES_PER_MB,
            users: data.users === '' ? null : Number(data.users),
            documents: data.documents === '' ? null : Number(data.documents),
            attachments:
                data.attachments === '' ? null : Number(data.attachments),
        }));

        limitsForm.patch(updateWorkspaceLimits.url(workspace.id), {
            preserveScroll: true,
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

    /**
     * A candidate is only worth answering with what stands behind it: which
     * kind of value it was seen introducing, and on how many documents.
     */
    const kindLabel = (kind: string): string =>
        kind === 'tax_id'
            ? t('workspace.settings.vocabulary_kind_tax_id')
            : kind === 'vehicle_registration'
              ? t('workspace.settings.vocabulary_kind_vehicle_registration')
              : kind;

    const evidence = (support: number): string =>
        support === 1
            ? t('workspace.settings.vocabulary_evidence_one', {
                  count: support,
              })
            : t('workspace.settings.vocabulary_evidence_other', {
                  count: support,
              });

    const answerLabel = (
        label: IntakeLabel,
        status: 'accepted' | 'rejected',
    ) => {
        router.patch(
            updateIntakeLabel.url([workspace.id, label.id]),
            { status },
            { preserveScroll: true },
        );
    };

    return (
        <PageContainer width="narrow">
            <PageHeader
                title={t('workspace.settings.title')}
                description={t('workspace.settings.description')}
            />

            <Card>
                <CardHeader>
                    <CardTitle>
                        {t('workspace.settings.workspace_section_title')}
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="flex items-center justify-between gap-4">
                        <div>
                            <div className="text-sm font-medium">
                                {t('workspace.settings.name_label')}
                            </div>
                            <div className="text-sm text-muted-foreground">
                                {workspace.name}
                            </div>
                        </div>
                        <Dialog open={renameOpen} onOpenChange={setRenameOpen}>
                            <DialogTrigger asChild>
                                <Button variant="outline" size="sm">
                                    {t('workspace.settings.rename_button')}
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogTitle>
                                    {t(
                                        'workspace.settings.rename_dialog_title',
                                    )}
                                </DialogTitle>
                                <form
                                    onSubmit={submitRename}
                                    className="space-y-4"
                                >
                                    <div className="grid gap-2">
                                        <Label htmlFor="name">
                                            {t('workspace.settings.name_label')}
                                        </Label>
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
                                                {t(
                                                    'workspace.settings.cancel_button',
                                                )}
                                            </Button>
                                        </DialogClose>
                                        <Button
                                            type="submit"
                                            disabled={renameForm.processing}
                                        >
                                            {t(
                                                'workspace.settings.save_button',
                                            )}
                                        </Button>
                                    </DialogFooter>
                                </form>
                            </DialogContent>
                        </Dialog>
                    </div>

                    <div className="flex items-center justify-between gap-4 border-t pt-4">
                        <div>
                            <div className="text-sm font-medium">
                                {t(
                                    'workspace.settings.organization_scheme_label',
                                )}
                            </div>
                            <div className="text-sm text-muted-foreground">
                                {scheme
                                    ? scheme.name
                                    : t('workspace.settings.no_scheme_text')}
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
                                {scheme
                                    ? t('workspace.settings.scheme_view_button')
                                    : t(
                                          'workspace.settings.scheme_create_button',
                                      )}
                            </Link>
                        </Button>
                    </div>
                </CardContent>
            </Card>

            {/*
             * Withheld on a demo. The limits are the ceiling that stops an
             * upload spree filling the volume before the nightly reset, and
             * the demo account is a platform admin, so the visitor would be
             * editing the only thing protecting the installation from them.
             * The read-only usage page still shows where a workspace stands.
             */}
            {isPlatformAdmin && !isDemo && (
                <Card>
                    <CardHeader>
                        <CardTitle>
                            {t('workspace.settings.limits_section_title')}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <p className="text-xs text-muted-foreground">
                            {t('workspace.settings.limits_section_description')}
                        </p>
                        <form
                            onSubmit={submitLimits}
                            className="grid grid-cols-1 gap-4 sm:grid-cols-2"
                        >
                            <div className="grid gap-2">
                                <Label htmlFor="storage_mb">
                                    {t(
                                        'workspace.settings.limits_storage_label',
                                    )}
                                </Label>
                                <Input
                                    id="storage_mb"
                                    type="number"
                                    min={0}
                                    value={limitsForm.data.storage_mb}
                                    onChange={(event) =>
                                        limitsForm.setData(
                                            'storage_mb',
                                            event.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    message={
                                        (
                                            limitsForm.errors as Record<
                                                string,
                                                string | undefined
                                            >
                                        ).storage_bytes
                                    }
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="users_limit">
                                    {t('workspace.settings.limits_users_label')}
                                </Label>
                                <Input
                                    id="users_limit"
                                    type="number"
                                    min={1}
                                    value={limitsForm.data.users}
                                    onChange={(event) =>
                                        limitsForm.setData(
                                            'users',
                                            event.target.value,
                                        )
                                    }
                                />
                                <InputError message={limitsForm.errors.users} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="documents_limit">
                                    {t(
                                        'workspace.settings.limits_documents_label',
                                    )}
                                </Label>
                                <Input
                                    id="documents_limit"
                                    type="number"
                                    min={0}
                                    value={limitsForm.data.documents}
                                    onChange={(event) =>
                                        limitsForm.setData(
                                            'documents',
                                            event.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    message={limitsForm.errors.documents}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="attachments_limit">
                                    {t(
                                        'workspace.settings.limits_attachments_label',
                                    )}
                                </Label>
                                <Input
                                    id="attachments_limit"
                                    type="number"
                                    min={0}
                                    value={limitsForm.data.attachments}
                                    onChange={(event) =>
                                        limitsForm.setData(
                                            'attachments',
                                            event.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    message={limitsForm.errors.attachments}
                                />
                            </div>
                            <div className="sm:col-span-2">
                                <Button
                                    type="submit"
                                    size="sm"
                                    disabled={limitsForm.processing}
                                >
                                    {t('workspace.settings.limits_save_button')}
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            )}

            {(intakeLabels.pending.length > 0 ||
                intakeLabels.accepted.length > 0) && (
                <Card>
                    <CardHeader>
                        <CardTitle>
                            {t('workspace.settings.vocabulary_section_title')}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <p className="text-xs text-muted-foreground">
                            {t('workspace.settings.vocabulary_description')}
                        </p>

                        {intakeLabels.pending.length > 0 && (
                            <div className="space-y-2">
                                <p className="text-xs font-medium text-muted-foreground">
                                    {t(
                                        'workspace.settings.vocabulary_pending_title',
                                    )}
                                </p>
                                {intakeLabels.pending.map((label) => (
                                    <div
                                        key={label.id}
                                        className="flex flex-wrap items-center gap-2 rounded-md border p-3"
                                    >
                                        <div className="min-w-0 flex-1">
                                            <div className="truncate text-sm font-medium">
                                                {label.label}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {kindLabel(label.kind)} ·{' '}
                                                {evidence(label.support)}
                                            </div>
                                        </div>
                                        <Button
                                            size="sm"
                                            className="shrink-0"
                                            onClick={() =>
                                                answerLabel(label, 'accepted')
                                            }
                                        >
                                            {t(
                                                'workspace.settings.vocabulary_accept',
                                            )}
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            className="shrink-0"
                                            onClick={() =>
                                                answerLabel(label, 'rejected')
                                            }
                                        >
                                            {t(
                                                'workspace.settings.vocabulary_reject',
                                            )}
                                        </Button>
                                    </div>
                                ))}
                            </div>
                        )}

                        {intakeLabels.accepted.length > 0 && (
                            <div className="space-y-2">
                                <p className="text-xs font-medium text-muted-foreground">
                                    {t(
                                        'workspace.settings.vocabulary_accepted_title',
                                    )}
                                </p>
                                {intakeLabels.accepted.map((label) => (
                                    <div
                                        key={label.id}
                                        className="flex flex-wrap items-center gap-2 rounded-md border p-3"
                                    >
                                        <div className="min-w-0 flex-1">
                                            <div className="truncate text-sm font-medium">
                                                {label.label}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {kindLabel(label.kind)}
                                            </div>
                                        </div>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            className="shrink-0"
                                            onClick={() =>
                                                answerLabel(label, 'rejected')
                                            }
                                        >
                                            {t(
                                                'workspace.settings.vocabulary_retire',
                                            )}
                                        </Button>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            )}

            <Card>
                <CardHeader>
                    <CardTitle>
                        {t('workspace.settings.instance_section_title')}
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-3">
                    <p className="text-xs text-muted-foreground">
                        {t('workspace.settings.instance_description')}
                    </p>
                    <div className="flex items-center justify-between">
                        <span className="text-sm">
                            {t('workspace.settings.multi_workspace_label')}
                        </span>
                        <Badge
                            variant={
                                instance.multi_workspace_enabled
                                    ? 'default'
                                    : 'outline'
                            }
                        >
                            {instance.multi_workspace_enabled
                                ? t('workspace.settings.enabled_badge')
                                : t('workspace.settings.disabled_badge')}
                        </Badge>
                    </div>
                    <div className="flex items-center justify-between">
                        <span className="text-sm">
                            {t('workspace.settings.attachments_disk_label')}
                        </span>
                        <Badge variant="secondary">
                            {instance.attachments_disk}
                        </Badge>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>
                        {t('workspace.settings.api_tokens_section_title')}
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-4">
                    {apiTokens.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            {t('workspace.settings.no_tokens_text')}
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
                                    {t(
                                        'workspace.settings.token_created_prefix',
                                    )}{' '}
                                    {token.created_at_diff} ·{' '}
                                    {token.last_used_at_diff
                                        ? `${t('workspace.settings.token_last_used_prefix')} ${token.last_used_at_diff}`
                                        : t(
                                              'workspace.settings.token_never_used',
                                          )}
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
                            <Label htmlFor="token_name">
                                {t('workspace.settings.new_token_name_label')}
                            </Label>
                            <Input
                                id="token_name"
                                placeholder={t(
                                    'workspace.settings.new_token_placeholder',
                                )}
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
                            <PlusIcon />{' '}
                            {t('workspace.settings.create_token_button')}
                        </Button>
                    </form>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>
                        {t('workspace.settings.account_section_title')}
                    </CardTitle>
                </CardHeader>
                <CardContent className="flex gap-2">
                    <Button variant="outline" size="sm" asChild>
                        <Link href={editProfile()}>
                            {t('workspace.settings.profile_link')}
                        </Link>
                    </Button>
                    <Button variant="outline" size="sm" asChild>
                        <Link href={editSecurity()}>
                            {t('workspace.settings.security_link')}
                        </Link>
                    </Button>
                </CardContent>
            </Card>

            {/*
             * Withheld on a demo, where this is the one button that empties
             * it for everybody until the next reset — and the confirm-by-name
             * dialog is no obstacle when the name is on the screen behind it.
             */}
            {!isDemo && (
                <Card className="border-red-100 dark:border-red-200/10">
                    <CardHeader>
                        <CardTitle>
                            {t(
                                'workspace.settings.delete_workspace_card_title',
                            )}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10">
                            <div className="space-y-0.5 text-red-600 dark:text-red-100">
                                <p className="font-medium">
                                    {t(
                                        'workspace.settings.delete_warning_title',
                                    )}
                                </p>
                                <p className="text-sm">
                                    {t(
                                        'workspace.settings.delete_warning_description',
                                    )}
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
                                        {t(
                                            'workspace.settings.delete_workspace_button',
                                        )}
                                    </Button>
                                </DialogTrigger>
                                <DialogContent>
                                    <DialogTitle>
                                        {t(
                                            'workspace.settings.delete_confirm_title',
                                            { name: workspace.name },
                                        )}
                                    </DialogTitle>
                                    <DialogDescription>
                                        {t(
                                            'workspace.settings.delete_confirm_description',
                                        )}{' '}
                                        <strong>{workspace.name}</strong>{' '}
                                        {t(
                                            'workspace.settings.delete_confirm_suffix',
                                        )}
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
                                                {t(
                                                    'workspace.settings.confirm_name_label',
                                                )}
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
                                                    {t(
                                                        'workspace.settings.cancel_button',
                                                    )}
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
                                                {t(
                                                    'workspace.settings.delete_workspace_button',
                                                )}
                                            </Button>
                                        </DialogFooter>
                                    </form>
                                </DialogContent>
                            </Dialog>
                        </div>
                    </CardContent>
                </Card>
            )}

            <Dialog
                open={revealedToken !== null}
                onOpenChange={(open) => !open && setRevealedToken(null)}
            >
                <DialogContent>
                    <DialogTitle>
                        {t('workspace.settings.token_created_dialog_title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t(
                            'workspace.settings.token_created_dialog_description',
                        )}
                    </DialogDescription>
                    <div className="flex items-center gap-2">
                        <Input readOnly value={revealedToken ?? ''} />
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => revealedToken && copy(revealedToken)}
                        >
                            {copiedText === revealedToken ? (
                                <CheckIcon />
                            ) : (
                                <CopyIcon />
                            )}
                        </Button>
                    </div>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button>
                                {t('workspace.settings.done_button')}
                            </Button>
                        </DialogClose>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </PageContainer>
    );
}
