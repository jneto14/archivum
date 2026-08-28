import { Head, router, setLayoutProps, usePage } from '@inertiajs/react';
import {
    ArrowRightLeftIcon,
    ChevronRightIcon,
    PlusIcon,
    Trash2Icon,
} from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Progress } from '@/components/ui/progress';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import nodeActions from '@/routes/organization/nodes';
import nodes from '@/routes/organization/schemes/nodes';

type StorageNode = {
    id: string;
    value: string;
    path: string;
    documents_count: number | null;
    children: StorageNode[];
};

type Level = {
    id: string;
    name: string;
    position: number;
    capacity: number | null;
    is_leaf: boolean;
};

type Props = {
    scheme: { id: string; name: string };
    levels: Level[];
    tree: StorageNode[];
    canManage: boolean;
};

function countNodes(nodes: StorageNode[]): number {
    return nodes.reduce(
        (total, node) => total + 1 + countNodes(node.children),
        0,
    );
}

function nodesAtDepth(nodes: StorageNode[], depth: number): StorageNode[] {
    if (depth === 0) {
        return nodes;
    }

    return nodes.flatMap((node) => nodesAtDepth(node.children, depth - 1));
}

export default function OrganizationStorage({
    scheme,
    levels,
    tree,
    canManage,
}: Props) {
    const { workspace } = usePage().props;
    const [view, setView] = useState<'tree' | 'columns'>('tree');
    const [columnSelection, setColumnSelection] = useState<StorageNode[]>([]);

    const [addLevelId, setAddLevelId] = useState('');
    const [addParentId, setAddParentId] = useState('');
    const [addValue, setAddValue] = useState('');
    const [addOpen, setAddOpen] = useState(false);

    const [moveSource, setMoveSource] = useState<StorageNode | null>(null);
    const [moveTargetId, setMoveTargetId] = useState('');

    setLayoutProps({
        breadcrumbs: [
            { title: workspace?.name ?? '', href: '#' },
            { title: 'Physical storage', href: '#' },
        ],
    });

    const nodeCount = countNodes(tree);
    const leafLevel = levels[levels.length - 1];
    const leafNodes = leafLevel ? nodesAtDepth(tree, levels.length - 1) : [];

    const addLevel = levels.find((level) => level.id === addLevelId) ?? null;
    const addParentOptions = addLevel
        ? nodesAtDepth(tree, addLevel.position - 2)
        : [];

    const openAdd = () => {
        setAddLevelId(leafLevel?.id ?? levels[0]?.id ?? '');
        setAddParentId('');
        setAddValue('');
        setAddOpen(true);
    };

    const submitAdd = () => {
        if (addLevel === null) {
            return;
        }

        router.post(
            nodes.store.url(scheme.id),
            {
                level_id: addLevel.id,
                parent_id: addParentId || null,
                value: addValue.trim() === '' ? null : addValue,
            },
            { preserveScroll: true, onSuccess: () => setAddOpen(false) },
        );
    };

    const deleteNode = (node: StorageNode) => {
        router.delete(nodes.destroy.url({ scheme: scheme.id, node: node.id }), {
            preserveScroll: true,
        });
    };

    const openMove = (node: StorageNode) => {
        setMoveSource(node);
        setMoveTargetId('');
    };

    const submitMove = () => {
        if (moveSource === null || moveTargetId === '') {
            return;
        }

        router.post(
            nodeActions.migrate.url(moveSource.id),
            { target_node_id: moveTargetId },
            { preserveScroll: true, onSuccess: () => setMoveSource(null) },
        );
    };

    const selectColumn = (depth: number, node: StorageNode) => {
        setColumnSelection([...columnSelection.slice(0, depth), node]);
    };

    return (
        <>
            <Head title="Physical storage" />

            <div className="max-w-6xl space-y-5 p-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Physical storage
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {scheme.name} ·{' '}
                            {levels.map((level) => level.name).join(' → ')}
                        </p>
                    </div>
                    <div className="flex overflow-hidden rounded-md border p-0.5">
                        <Button
                            type="button"
                            size="sm"
                            variant={view === 'tree' ? 'secondary' : 'ghost'}
                            onClick={() => setView('tree')}
                        >
                            Tree
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant={view === 'columns' ? 'secondary' : 'ghost'}
                            onClick={() => setView('columns')}
                        >
                            Columns
                        </Button>
                    </div>
                </div>

                {view === 'tree' && (
                    <div className="overflow-hidden rounded-xl border">
                        <div className="flex items-center justify-between border-b bg-muted px-4 py-3">
                            <span className="text-xs font-medium text-muted-foreground">
                                {nodeCount} node{nodeCount === 1 ? '' : 's'}
                            </span>
                            {canManage && (
                                <Button size="sm" onClick={openAdd}>
                                    <PlusIcon /> Add node
                                </Button>
                            )}
                        </div>
                        {nodeCount === 0 && (
                            <p className="p-6 text-center text-sm text-muted-foreground">
                                No locations yet.
                            </p>
                        )}
                        <TreeRows
                            nodes={tree}
                            depth={0}
                            levels={levels}
                            canManage={canManage}
                            onDelete={deleteNode}
                            onMove={openMove}
                        />
                    </div>
                )}

                {view === 'columns' && (
                    <div
                        className="grid overflow-hidden rounded-xl border"
                        style={{
                            gridTemplateColumns: `repeat(${levels.length}, 1fr)`,
                        }}
                    >
                        {levels.map((level, depth) => {
                            const items =
                                depth === 0
                                    ? tree
                                    : (columnSelection[depth - 1]?.children ??
                                      []);
                            const isLastColumn = depth === levels.length - 1;

                            return (
                                <div
                                    key={level.id}
                                    className="flex min-h-[420px] flex-col border-r last:border-r-0"
                                >
                                    <div className="border-b bg-muted px-4 py-2.5 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                                        {level.name}
                                    </div>
                                    {items.map((node) => {
                                        const pct =
                                            level.capacity !== null &&
                                            node.documents_count !== null
                                                ? Math.round(
                                                      (node.documents_count /
                                                          level.capacity) *
                                                          100,
                                                  )
                                                : null;

                                        if (isLastColumn) {
                                            return (
                                                <div
                                                    key={node.id}
                                                    className="space-y-1.5 border-b px-4 py-2.5"
                                                >
                                                    <div className="flex items-center justify-between gap-2">
                                                        <span className="font-mono text-sm font-medium">
                                                            {node.value}
                                                        </span>
                                                        <span className="font-mono text-xs text-muted-foreground">
                                                            {
                                                                node.documents_count
                                                            }
                                                            {level.capacity !==
                                                            null
                                                                ? ` / ${level.capacity}`
                                                                : ''}
                                                        </span>
                                                    </div>
                                                    {pct !== null && (
                                                        <Progress value={pct} />
                                                    )}
                                                </div>
                                            );
                                        }

                                        return (
                                            <button
                                                key={node.id}
                                                type="button"
                                                onClick={() =>
                                                    selectColumn(depth, node)
                                                }
                                                className="flex items-center justify-between gap-2 border-b px-4 py-2.5 text-left hover:bg-muted"
                                                style={{
                                                    background:
                                                        columnSelection[depth]
                                                            ?.id === node.id
                                                            ? 'var(--muted)'
                                                            : undefined,
                                                }}
                                            >
                                                <span className="font-mono text-sm font-medium">
                                                    {node.value}
                                                </span>
                                                <span className="text-xs text-muted-foreground">
                                                    {node.children.length}
                                                </span>
                                            </button>
                                        );
                                    })}
                                </div>
                            );
                        })}
                    </div>
                )}
            </div>

            <Dialog open={addOpen} onOpenChange={setAddOpen}>
                <DialogContent>
                    <DialogTitle>Add node</DialogTitle>
                    <div className="space-y-4">
                        <div className="grid gap-2">
                            <Label>Level</Label>
                            <Select
                                value={addLevelId}
                                onValueChange={(value) => {
                                    setAddLevelId(value);
                                    setAddParentId('');
                                }}
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="Select a level" />
                                </SelectTrigger>
                                <SelectContent>
                                    {levels.map((level) => (
                                        <SelectItem
                                            key={level.id}
                                            value={level.id}
                                        >
                                            {level.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        {addLevel && addLevel.position > 1 && (
                            <div className="grid gap-2">
                                <Label>Parent location</Label>
                                <Select
                                    value={addParentId}
                                    onValueChange={setAddParentId}
                                >
                                    <SelectTrigger className="w-full">
                                        <SelectValue placeholder="Select a parent" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {addParentOptions.map((parent) => (
                                            <SelectItem
                                                key={parent.id}
                                                value={parent.id}
                                            >
                                                {parent.path}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        )}
                        <div className="grid gap-2">
                            <Label htmlFor="node_value">
                                Value (leave blank to auto-generate)
                            </Label>
                            <Input
                                id="node_value"
                                value={addValue}
                                onChange={(event) =>
                                    setAddValue(event.target.value)
                                }
                                placeholder="A"
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button variant="ghost">Cancel</Button>
                        </DialogClose>
                        <Button onClick={submitAdd}>Add</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={moveSource !== null}
                onOpenChange={(open) => !open && setMoveSource(null)}
            >
                <DialogContent>
                    <DialogTitle>
                        Move documents from {moveSource?.path}
                    </DialogTitle>
                    <div className="space-y-4">
                        <p className="text-sm text-muted-foreground">
                            Queues a background job that relocates every
                            document currently filed at this location onto the
                            selected one.
                        </p>
                        <div className="grid gap-2">
                            <Label>Target location</Label>
                            <Select
                                value={moveTargetId}
                                onValueChange={setMoveTargetId}
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="Select a location" />
                                </SelectTrigger>
                                <SelectContent>
                                    {leafNodes
                                        .filter(
                                            (node) =>
                                                node.id !== moveSource?.id,
                                        )
                                        .map((node) => (
                                            <SelectItem
                                                key={node.id}
                                                value={node.id}
                                            >
                                                {node.path}
                                            </SelectItem>
                                        ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button variant="ghost">Cancel</Button>
                        </DialogClose>
                        <Button onClick={submitMove}>Move</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

type TreeRowsProps = {
    nodes: StorageNode[];
    depth: number;
    levels: Level[];
    canManage: boolean;
    onDelete: (node: StorageNode) => void;
    onMove: (node: StorageNode) => void;
};

function TreeRows({
    nodes,
    depth,
    levels,
    canManage,
    onDelete,
    onMove,
}: TreeRowsProps) {
    const level = levels[depth];

    if (!level) {
        return null;
    }

    return (
        <>
            {nodes.map((node) => {
                const hasChildren = node.children.length > 0;
                const pct =
                    level.capacity !== null && node.documents_count !== null
                        ? Math.round(
                              (node.documents_count / level.capacity) * 100,
                          )
                        : null;

                return (
                    <div key={node.id}>
                        <div
                            className="flex items-center gap-2.5 border-b px-4 py-2.5"
                            style={{ paddingLeft: 16 + depth * 22 }}
                        >
                            <span className="flex w-3.5 flex-none items-center text-muted-foreground">
                                {hasChildren && (
                                    <ChevronRightIcon className="size-3.5" />
                                )}
                            </span>
                            <span className="min-w-[110px] flex-none font-mono text-sm font-medium">
                                {node.value}
                            </span>
                            <span className="flex-1 truncate text-xs text-muted-foreground">
                                {level.is_leaf
                                    ? `${node.documents_count} document${node.documents_count === 1 ? '' : 's'}${level.capacity !== null ? ` / ${level.capacity}` : ''}`
                                    : `${node.children.length} location${node.children.length === 1 ? '' : 's'}`}
                            </span>
                            {pct !== null && (
                                <div className="w-24 flex-none">
                                    <Progress value={pct} />
                                </div>
                            )}
                            {canManage && (
                                <div className="flex flex-none items-center gap-1">
                                    {level.is_leaf && (
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            title="Move documents"
                                            onClick={() => onMove(node)}
                                        >
                                            <ArrowRightLeftIcon className="size-3.5" />
                                        </Button>
                                    )}
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        title="Delete location"
                                        onClick={() => onDelete(node)}
                                    >
                                        <Trash2Icon className="size-3.5" />
                                    </Button>
                                </div>
                            )}
                        </div>
                        {hasChildren && (
                            <TreeRows
                                nodes={node.children}
                                depth={depth + 1}
                                levels={levels}
                                canManage={canManage}
                                onDelete={onDelete}
                                onMove={onMove}
                            />
                        )}
                    </div>
                );
            })}
        </>
    );
}
