import { Head, router, setLayoutProps } from '@inertiajs/react';
import { CheckIcon, PencilIcon, Trash2Icon, XIcon } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { index as documentsIndex } from '@/routes/documents';
import { destroy, index, store, update } from '@/routes/tags';

type TagRow = {
    id: string;
    name: string;
    documents_count: number;
    last_used_at: string | null;
};

type Props = {
    workspace: { id: string; name: string };
    tags: TagRow[];
};

export default function TagIndex({ workspace, tags }: Props) {
    const [newName, setNewName] = useState('');
    const [renamingId, setRenamingId] = useState<string | null>(null);
    const [renameValue, setRenameValue] = useState('');

    setLayoutProps({
        breadcrumbs: [
            { title: workspace.name, href: '#' },
            { title: 'Tags', href: index.url(workspace.id) },
        ],
    });

    const createTag = () => {
        if (newName.trim() === '') {
            return;
        }

        router.post(
            store.url(workspace.id),
            { name: newName },
            { preserveScroll: true, onSuccess: () => setNewName('') },
        );
    };

    const startRename = (tag: TagRow) => {
        setRenamingId(tag.id);
        setRenameValue(tag.name);
    };

    const submitRename = () => {
        if (renamingId === null || renameValue.trim() === '') {
            return;
        }

        router.patch(
            update.url(renamingId),
            { name: renameValue },
            { preserveScroll: true, onSuccess: () => setRenamingId(null) },
        );
    };

    const removeTag = (tag: TagRow) => {
        router.delete(destroy.url(tag.id), { preserveScroll: true });
    };

    const showDocuments = (tag: TagRow) => {
        router.get(documentsIndex.url(workspace.id), { tag_ids: [tag.id] });
    };

    return (
        <>
            <Head title="Tags" />

            <div className="mx-auto max-w-3xl space-y-6 p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Tags
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Tags are workspace-scoped and independent from document
                        types and physical storage.
                    </p>
                </div>

                <div className="flex flex-wrap gap-2.5">
                    <Input
                        placeholder="New tag name"
                        value={newName}
                        onChange={(event) => setNewName(event.target.value)}
                        onKeyDown={(event) =>
                            event.key === 'Enter' && createTag()
                        }
                        className="max-w-xs"
                    />
                    <Button size="sm" onClick={createTag}>
                        Create tag
                    </Button>
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
                        {tags.map((tag) => (
                            <div
                                key={tag.id}
                                className="flex flex-wrap items-center gap-3.5 border-b px-4.5 py-3 last:border-b-0"
                            >
                                {renamingId === tag.id ? (
                                    <>
                                        <Input
                                            autoFocus
                                            value={renameValue}
                                            onChange={(event) =>
                                                setRenameValue(
                                                    event.target.value,
                                                )
                                            }
                                            onKeyDown={(event) => {
                                                if (event.key === 'Enter') {
                                                    submitRename();
                                                }

                                                if (event.key === 'Escape') {
                                                    setRenamingId(null);
                                                }
                                            }}
                                            className="h-7 w-40"
                                        />
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            title="Save"
                                            onClick={submitRename}
                                        >
                                            <CheckIcon className="size-3.5" />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            title="Cancel"
                                            onClick={() => setRenamingId(null)}
                                        >
                                            <XIcon className="size-3.5" />
                                        </Button>
                                    </>
                                ) : (
                                    <Badge variant="outline">{tag.name}</Badge>
                                )}
                                <span className="min-w-0 flex-1 text-xs text-muted-foreground">
                                    {tag.documents_count} document
                                    {tag.documents_count === 1 ? '' : 's'} ·
                                    last used{' '}
                                    {tag.last_used_at
                                        ? new Date(
                                              tag.last_used_at,
                                          ).toLocaleDateString()
                                        : 'never'}
                                </span>
                                <button
                                    type="button"
                                    onClick={() => showDocuments(tag)}
                                    className="flex-none text-xs font-medium text-foreground underline decoration-foreground/40 underline-offset-4"
                                >
                                    Show documents
                                </button>
                                {renamingId !== tag.id && (
                                    <div className="flex flex-none items-center gap-1">
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            title="Rename tag"
                                            onClick={() => startRename(tag)}
                                        >
                                            <PencilIcon className="size-3.5" />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            title="Delete tag"
                                            onClick={() => removeTag(tag)}
                                        >
                                            <Trash2Icon className="size-3.5" />
                                        </Button>
                                    </div>
                                )}
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}
