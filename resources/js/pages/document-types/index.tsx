import {
    Head,
    router,
    setLayoutProps,
    useForm,
    usePage,
} from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
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
    const { errors } = usePage().props;
    const [editing, setEditing] = useState<DocumentTypeRow | null>(null);
    const [createOpen, setCreateOpen] = useState(false);
    const [removeTarget, setRemoveTarget] = useState<DocumentTypeRow | null>(
        null,
    );
    const form = useForm({ name: '', key: '' });

    setLayoutProps({
        breadcrumbs: [
            { title: workspace.name, href: '#' },
            { title: 'Document types', href: index.url(workspace.id) },
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
            <Head title="Document types" />

            <div className="mx-auto max-w-3xl space-y-6 p-6">
                <div className="flex items-end justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Document types
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {documentTypes.length} type
                            {documentTypes.length === 1 ? '' : 's'}
                        </p>
                    </div>
                    {canManage && (
                        <Dialog open={createOpen} onOpenChange={setCreateOpen}>
                            <DialogTrigger asChild>
                                <Button size="sm" onClick={openCreate}>
                                    New type
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogTitle>New document type</DialogTitle>
                                <div className="space-y-4">
                                    <div className="grid gap-2">
                                        <Label htmlFor="name">Name</Label>
                                        <Input
                                            id="name"
                                            placeholder="Invoice"
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
                                        <Label htmlFor="key">Key</Label>
                                        <Input
                                            id="key"
                                            placeholder="invoice"
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
                                        <Button variant="ghost">Cancel</Button>
                                    </DialogClose>
                                    <Button
                                        onClick={submitCreate}
                                        disabled={form.processing}
                                    >
                                        Create
                                    </Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
                    )}
                </div>

                {errors.document_type && (
                    <p className="text-sm text-destructive">
                        {errors.document_type}
                    </p>
                )}

                {documentTypes.length === 0 ? (
                    <div className="rounded-xl border border-dashed p-12 text-center">
                        <div className="font-semibold">
                            No document types yet
                        </div>
                        <div className="text-sm text-muted-foreground">
                            {canManage
                                ? 'Create a type to start registering documents.'
                                : 'An admin has not defined any document types yet.'}
                        </div>
                    </div>
                ) : (
                    <div className="overflow-hidden rounded-xl border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Key</TableHead>
                                    <TableHead>Documents</TableHead>
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
                                                        Edit
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
                                                                        Delete
                                                                    </Button>
                                                                </span>
                                                            </TooltipTrigger>
                                                            <TooltipContent>
                                                                {
                                                                    type.documents_count
                                                                }{' '}
                                                                document
                                                                {type.documents_count ===
                                                                1
                                                                    ? ''
                                                                    : 's'}{' '}
                                                                still assigned —
                                                                reassign or
                                                                remove them
                                                                first.
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
                                                            Delete
                                                        </Button>
                                                    )}
                                                </div>
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </div>

            <Dialog
                open={editing !== null}
                onOpenChange={(open) => !open && setEditing(null)}
            >
                <DialogContent>
                    <DialogTitle>Edit document type</DialogTitle>
                    <div className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor="edit_name">Name</Label>
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
                            <Label htmlFor="edit_key">Key</Label>
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
                            <Button variant="ghost">Cancel</Button>
                        </DialogClose>
                        <Button onClick={submitEdit} disabled={form.processing}>
                            Save changes
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={removeTarget !== null}
                onOpenChange={(open) => !open && setRemoveTarget(null)}
            >
                <DialogContent>
                    <DialogTitle>Delete this document type?</DialogTitle>
                    <p className="text-sm text-muted-foreground">
                        {removeTarget?.name} will no longer be available when
                        registering documents.
                    </p>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button variant="secondary">Cancel</Button>
                        </DialogClose>
                        <Button variant="destructive" onClick={confirmRemove}>
                            Delete
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
