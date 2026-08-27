import { Head, router, setLayoutProps, useForm } from '@inertiajs/react';
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
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { destroy, index, store, update } from '@/routes/tags';

type TagRow = {
    id: string;
    name: string;
    documents_count: number;
};

type Props = {
    workspace: { id: string; name: string };
    tags: TagRow[];
};

export default function TagIndex({ workspace, tags }: Props) {
    const [editing, setEditing] = useState<TagRow | null>(null);
    const [createOpen, setCreateOpen] = useState(false);
    const form = useForm({ name: '' });

    setLayoutProps({
        breadcrumbs: [
            { title: workspace.name, href: '#' },
            { title: 'Tags', href: index.url(workspace.id) },
        ],
    });

    const openCreate = () => {
        form.reset();
        form.clearErrors();
        setCreateOpen(true);
    };

    const openEdit = (tag: TagRow) => {
        form.setData({ name: tag.name });
        form.clearErrors();
        setEditing(tag);
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

    const removeTag = (tag: TagRow) => {
        router.delete(destroy.url(tag.id), { preserveScroll: true });
    };

    return (
        <>
            <Head title="Tags" />

            <div className="mx-auto max-w-3xl space-y-6 p-6">
                <div className="flex items-end justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Tags
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {tags.length} tag{tags.length === 1 ? '' : 's'}
                        </p>
                    </div>
                    <Dialog open={createOpen} onOpenChange={setCreateOpen}>
                        <DialogTrigger asChild>
                            <Button size="sm" onClick={openCreate}>
                                New tag
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogTitle>New tag</DialogTitle>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Name</Label>
                                <Input
                                    id="name"
                                    value={form.data.name}
                                    onChange={(event) =>
                                        form.setData('name', event.target.value)
                                    }
                                />
                                <InputError message={form.errors.name} />
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
                </div>

                {tags.length === 0 ? (
                    <div className="rounded-xl border border-dashed p-12 text-center">
                        <div className="font-semibold">No tags yet</div>
                        <div className="text-sm text-muted-foreground">
                            Create a tag to start labeling documents.
                        </div>
                    </div>
                ) : (
                    <div className="overflow-hidden rounded-xl border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Documents</TableHead>
                                    <TableHead className="w-32" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {tags.map((tag) => (
                                    <TableRow key={tag.id}>
                                        <TableCell className="font-medium">
                                            {tag.name}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {tag.documents_count}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex items-center gap-1">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        openEdit(tag)
                                                    }
                                                >
                                                    Edit
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        removeTag(tag)
                                                    }
                                                >
                                                    Delete
                                                </Button>
                                            </div>
                                        </TableCell>
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
                    <DialogTitle>Edit tag</DialogTitle>
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
        </>
    );
}
