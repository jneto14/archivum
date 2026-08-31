import {
    Head,
    router,
    setLayoutProps,
    useForm,
    usePage,
} from '@inertiajs/react';
import { useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import InputError from '@/components/input-error';
import { PageContainer } from '@/components/page-container';
import { PageHeader } from '@/components/page-header';
import { Panel } from '@/components/panel';
import { Badge } from '@/components/ui/badge';
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
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useTranslation } from '@/hooks/use-translation';
import { destroy, index, store, update } from '@/routes/document-types';

type DocumentTypeRow = {
    id: string;
    name: string;
    key: string;
    documents_count: number;
};

type Props = {
    workspace: { id: string; name: string };
    documentTypes: DocumentTypeRow[];
    canManage: boolean;
};

export default function DocumentTypeIndex({
    workspace,
    documentTypes,
    canManage,
}: Props) {
    const t = useTranslation();
    const { errors } = usePage().props;
    const [editing, setEditing] = useState<DocumentTypeRow | null>(null);
    const [createOpen, setCreateOpen] = useState(false);
    const [removeTarget, setRemoveTarget] = useState<DocumentTypeRow | null>(
        null,
    );
    const form = useForm({ name: '', key: '' });

    setLayoutProps({
        breadcrumbs: [
            {
                title: t('document_types.index.breadcrumb'),
                href: index.url(workspace.id),
            },
        ],
    });

    const openCreate = () => {
        form.reset();
        form.clearErrors();
        setCreateOpen(true);
    };

    const openEdit = (type: DocumentTypeRow) => {
        form.setData({ name: type.name, key: type.key });
        form.clearErrors();
        setEditing(type);
    };

    const submitCreate = () => {
        form.post(store.url(workspace.id), {
            preserveScroll: true,
            onSuccess: () => setCreateOpen(false),
        });
    };

    const submitEdit = () => {
        if (editing === null) {
            return;
        }

        form.patch(update.url(editing.id), {
            preserveScroll: true,
            onSuccess: () => setEditing(null),
        });
    };

    const confirmRemove = () => {
        if (removeTarget === null) {
            return;
        }

        router.delete(destroy.url(removeTarget.id), {
            preserveScroll: true,
            onFinish: () => setRemoveTarget(null),
        });
    };

    return (
        <>
            <Head title={t('document_types.index.head_title')} />

            <PageContainer>
                <PageHeader
                    title={t('document_types.index.title')}
                    description={
                        documentTypes.length === 1
                            ? t('document_types.index.type_count_one', {
                                  count: documentTypes.length,
                              })
                            : t('document_types.index.type_count_other', {
                                  count: documentTypes.length,
                              })
                    }
                >
                    {canManage && (
                        <Dialog open={createOpen} onOpenChange={setCreateOpen}>
                            <DialogTrigger asChild>
                                <Button size="sm" onClick={openCreate}>
                                    {t('document_types.index.new_type_button')}
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogTitle>
                                    {t(
                                        'document_types.index.create_dialog_title',
                                    )}
                                </DialogTitle>
                                <div className="space-y-4">
                                    <div className="grid gap-2">
                                        <Label htmlFor="name">
                                            {t(
                                                'document_types.index.name_label',
                                            )}
                                        </Label>
                                        <Input
                                            id="name"
                                            placeholder={t(
                                                'document_types.index.name_placeholder',
                                            )}
                                            value={form.data.name}
                                            onChange={(event) =>
                                                form.setData(
                                                    'name',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        <InputError
                                            message={form.errors.name}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="key">
                                            {t(
                                                'document_types.index.key_label',
                                            )}
                                        </Label>
                                        <Input
                                            id="key"
                                            placeholder={t(
                                                'document_types.index.key_placeholder',
                                            )}
                                            value={form.data.key}
                                            onChange={(event) =>
                                                form.setData(
                                                    'key',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        <InputError message={form.errors.key} />
                                    </div>
                                </div>
                                <DialogFooter>
                                    <DialogClose asChild>
                                        <Button variant="ghost">
                                            {t(
                                                'document_types.index.cancel_button',
                                            )}
                                        </Button>
                                    </DialogClose>
                                    <Button
                                        onClick={submitCreate}
                                        disabled={form.processing}
                                    >
                                        {t(
                                            'document_types.index.create_button',
                                        )}
                                    </Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
                    )}
                </PageHeader>

                {errors.document_type && (
                    <p className="text-sm text-destructive">
                        {errors.document_type}
                    </p>
                )}

                {documentTypes.length === 0 ? (
                    <EmptyState
                        title={t('document_types.index.empty_title')}
                        description={
                            canManage
                                ? t(
                                      'document_types.index.empty_description_manager',
                                  )
                                : t(
                                      'document_types.index.empty_description_viewer',
                                  )
                        }
                    />
                ) : (
                    <Panel>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>
                                        {t('document_types.index.name_column')}
                                    </TableHead>
                                    <TableHead>
                                        {t('document_types.index.key_column')}
                                    </TableHead>
                                    <TableHead>
                                        {t(
                                            'document_types.index.documents_column',
                                        )}
                                    </TableHead>
                                    {canManage && (
                                        <TableHead className="w-32" />
                                    )}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {documentTypes.map((type) => (
                                    <TableRow key={type.id}>
                                        <TableCell className="font-medium">
                                            {type.name}
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant="secondary">
                                                {type.key}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {type.documents_count}
                                        </TableCell>
                                        {canManage && (
                                            <TableCell>
                                                <div className="flex items-center gap-1">
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            openEdit(type)
                                                        }
                                                    >
                                                        {t(
                                                            'document_types.index.edit_button',
                                                        )}
                                                    </Button>
                                                    {type.documents_count >
                                                    0 ? (
                                                        <Tooltip>
                                                            <TooltipTrigger
                                                                asChild
                                                            >
                                                                <span
                                                                    tabIndex={0}
                                                                >
                                                                    <Button
                                                                        variant="ghost"
                                                                        size="sm"
                                                                        disabled
                                                                    >
                                                                        {t(
                                                                            'document_types.index.delete_button',
                                                                        )}
                                                                    </Button>
                                                                </span>
                                                            </TooltipTrigger>
                                                            <TooltipContent>
                                                                {type.documents_count ===
                                                                1
                                                                    ? t(
                                                                          'document_types.index.delete_disabled_tooltip_one',
                                                                          {
                                                                              count: type.documents_count,
                                                                          },
                                                                      )
                                                                    : t(
                                                                          'document_types.index.delete_disabled_tooltip_other',
                                                                          {
                                                                              count: type.documents_count,
                                                                          },
                                                                      )}
                                                            </TooltipContent>
                                                        </Tooltip>
                                                    ) : (
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() =>
                                                                setRemoveTarget(
                                                                    type,
                                                                )
                                                            }
                                                        >
                                                            {t(
                                                                'document_types.index.delete_button',
                                                            )}
                                                        </Button>
                                                    )}
                                                </div>
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </Panel>
                )}
            </PageContainer>

            <Dialog
                open={editing !== null}
                onOpenChange={(open) => !open && setEditing(null)}
            >
                <DialogContent>
                    <DialogTitle>
                        {t('document_types.index.edit_dialog_title')}
                    </DialogTitle>
                    <div className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor="edit_name">
                                {t('document_types.index.name_label')}
                            </Label>
                            <Input
                                id="edit_name"
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                            />
                            <InputError message={form.errors.name} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="edit_key">
                                {t('document_types.index.key_label')}
                            </Label>
                            <Input
                                id="edit_key"
                                value={form.data.key}
                                onChange={(event) =>
                                    form.setData('key', event.target.value)
                                }
                            />
                            <InputError message={form.errors.key} />
                        </div>
                    </div>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button variant="ghost">
                                {t('document_types.index.cancel_button')}
                            </Button>
                        </DialogClose>
                        <Button onClick={submitEdit} disabled={form.processing}>
                            {t('document_types.index.save_changes_button')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={removeTarget !== null}
                onOpenChange={(open) => !open && setRemoveTarget(null)}
            >
                <DialogContent>
                    <DialogTitle>
                        {t('document_types.index.delete_dialog_title')}
                    </DialogTitle>
                    <p className="text-sm text-muted-foreground">
                        {t('document_types.index.delete_dialog_description', {
                            name: removeTarget?.name ?? '',
                        })}
                    </p>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button variant="secondary">
                                {t('document_types.index.cancel_button')}
                            </Button>
                        </DialogClose>
                        <Button variant="destructive" onClick={confirmRemove}>
                            {t('document_types.index.delete_button')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
