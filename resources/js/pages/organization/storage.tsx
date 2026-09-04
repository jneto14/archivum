import { Head, router, setLayoutProps, usePage } from '@inertiajs/react';
import {
    ArrowRightLeftIcon,
    ChevronRightIcon,
    Columns3Icon,
    ListTreeIcon,
    PlusIcon,
    Trash2Icon,
} from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { PageContainer } from '@/components/page-container';
import { PageHeader } from '@/components/page-header';
import { Panel, PanelHeader } from '@/components/panel';
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
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useTranslation } from '@/hooks/use-translation';
import {
    countNodes,
    firstEmptyDepth,
    nodesAtDepth,
} from '@/lib/organization-tree';
import nodeActions from '@/routes/organization/nodes';
import nodes from '@/routes/organization/schemes/nodes';

type StorageView = 'tree' | 'columns';

/**
 * What a value looks like at each strategy. A Manual level has nothing to
 * suggest — the user names the node — so it shows no example rather than one
 * borrowed from a strategy it does not use.
 */
const valuePlaceholders = {
    manual: '',
    sequential: '001',
    alphabetical: 'A',
} as const;

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
    value_strategy: 'manual' | 'sequential' | 'alphabetical';
    is_leaf: boolean;
};

type Props = {
    scheme: { id: string; name: string };
    levels: Level[];
    tree: StorageNode[];
    canManage: boolean;
};

export default function OrganizationStorage({
    scheme,
    levels,
    tree,
    canManage,
}: Props) {
    const t = useTranslation();
    const { errors } = usePage().props;
    const [view, setView] = useState<StorageView>('tree');
    const [columnSelection, setColumnSelection] = useState<StorageNode[]>([]);

    const [addLevelId, setAddLevelId] = useState('');
    const [addParentId, setAddParentId] = useState('');
    const [addValue, setAddValue] = useState('');
    const [addOpen, setAddOpen] = useState(false);

    const [moveSource, setMoveSource] = useState<StorageNode | null>(null);
    const [moveTargetId, setMoveTargetId] = useState('');

    setLayoutProps({
        breadcrumbs: [{ title: t('organization.storage.title'), href: '#' }],
    });

    const nodeCount = countNodes(tree);
    const leafLevel = levels[levels.length - 1];
    const leafNodes = leafLevel ? nodesAtDepth(tree, levels.length - 1) : [];

    // A level's depth is where it sits in `levels` (ordered by position), not
    // its position value: a scheme's levels are not guaranteed to start at 1.
    const addLevelDepth = levels.findIndex((level) => level.id === addLevelId);
    const addLevel = addLevelDepth === -1 ? null : levels[addLevelDepth];
    const addParentOptions =
        addLevel === null ? [] : nodesAtDepth(tree, addLevelDepth - 1);
    const isLevelAddable = (depth: number) =>
        depth === 0 || nodesAtDepth(tree, depth - 1).length > 0;

    const openAdd = () => {
        const emptyDepth = firstEmptyDepth(levels.length, tree);
        const target =
            (emptyDepth === null ? null : levels[emptyDepth]) ??
            leafLevel ??
            levels[0];
        setAddLevelId(target?.id ?? '');
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
            <Head title={t('organization.storage.title')} />

            <PageContainer width="wide">
                <PageHeader
                    title={t('organization.storage.title')}
                    description={`${scheme.name} · ${levels
                        .map((level) => level.name)
                        .join(' → ')}`}
                >
                    <ToggleGroup
                        type="single"
                        variant="outline"
                        size="sm"
                        value={view}
                        onValueChange={(value) =>
                            value !== '' && setView(value as StorageView)
                        }
                    >
                        <ToggleGroupItem value="tree">
                            <ListTreeIcon />
                            {t('organization.storage.view_tree')}
                        </ToggleGroupItem>
                        <ToggleGroupItem value="columns">
                            <Columns3Icon />
                            {t('organization.storage.view_columns')}
                        </ToggleGroupItem>
                    </ToggleGroup>
                </PageHeader>

                {view === 'tree' && (
                    <Panel>
                        <PanelHeader>
                            <span className="text-xs font-medium text-muted-foreground">
                                {t(
                                    nodeCount === 1
                                        ? 'organization.storage.node_count_one'
                                        : 'organization.storage.node_count_other',
                                    { count: nodeCount },
                                )}
                            </span>
                            {canManage && (
                                <Button size="sm" onClick={openAdd}>
                                    <PlusIcon />{' '}
                                    {t('organization.storage.add_node')}
                                </Button>
                            )}
                        </PanelHeader>
                        {nodeCount === 0 && (
                            <p className="p-6 text-center text-sm text-muted-foreground">
                                {t('organization.storage.empty_state')}
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
                    </Panel>
                )}

                {view === 'columns' && (
                    <Panel>
                        <div
                            className="grid overflow-x-auto"
                            style={{
                                gridTemplateColumns: `repeat(${levels.length}, minmax(13rem, 1fr))`,
                            }}
                        >
                            {levels.map((level, depth) => {
                                const items =
                                    depth === 0
                                        ? tree
                                        : (columnSelection[depth - 1]
                                              ?.children ?? []);
                                const isLastColumn =
                                    depth === levels.length - 1;

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
                                                            <Progress
                                                                value={pct}
                                                            />
                                                        )}
                                                    </div>
                                                );
                                            }

                                            return (
                                                <button
                                                    key={node.id}
                                                    type="button"
                                                    onClick={() =>
                                                        selectColumn(
                                                            depth,
                                                            node,
                                                        )
                                                    }
                                                    className="flex items-center justify-between gap-2 border-b px-4 py-2.5 text-left hover:bg-muted"
                                                    style={{
                                                        background:
                                                            columnSelection[
                                                                depth
                                                            ]?.id === node.id
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
                    </Panel>
                )}
            </PageContainer>

            <Dialog open={addOpen} onOpenChange={setAddOpen}>
                <DialogContent>
                    <DialogTitle>
                        {t('organization.storage.add_node')}
                    </DialogTitle>
                    <div className="space-y-4">
                        <div className="grid gap-2">
                            <Label>
                                {t('organization.storage.level_label')}
                            </Label>
                            <Select
                                value={addLevelId}
                                onValueChange={(value) => {
                                    setAddLevelId(value);
                                    setAddParentId('');
                                }}
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue
                                        placeholder={t(
                                            'organization.storage.select_level_placeholder',
                                        )}
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    {levels.map((level, index) => (
                                        <SelectItem
                                            key={level.id}
                                            value={level.id}
                                            disabled={!isLevelAddable(index)}
                                        >
                                            {level.name}
                                            {!isLevelAddable(index) &&
                                                ` ${t('organization.storage.add_level_first_hint', { level: levels[index - 1]?.name ?? '' })}`}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.level_id} />
                        </div>
                        {addLevel && addLevelDepth > 0 && (
                            <div className="grid gap-2">
                                <Label>
                                    {t(
                                        'organization.storage.parent_location_label',
                                    )}
                                </Label>
                                <Select
                                    value={addParentId}
                                    onValueChange={setAddParentId}
                                >
                                    <SelectTrigger className="w-full">
                                        <SelectValue
                                            placeholder={t(
                                                'organization.storage.select_parent_placeholder',
                                            )}
                                        />
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
                                <InputError message={errors.parent_id} />
                            </div>
                        )}
                        <div className="grid gap-2">
                            <Label htmlFor="node_value">
                                {addLevel?.value_strategy === 'manual'
                                    ? t('organization.storage.value_label')
                                    : t(
                                          'organization.storage.value_label_optional',
                                      )}
                            </Label>
                            <Input
                                id="node_value"
                                value={addValue}
                                onChange={(event) =>
                                    setAddValue(event.target.value)
                                }
                                placeholder={
                                    valuePlaceholders[
                                        addLevel?.value_strategy ?? 'manual'
                                    ]
                                }
                            />
                            <InputError message={errors.value} />
                            <InputError message={errors.capacity} />
                        </div>
                    </div>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button variant="ghost">
                                {t('organization.storage.cancel_button')}
                            </Button>
                        </DialogClose>
                        <Button onClick={submitAdd}>
                            {t('organization.storage.add_button')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={moveSource !== null}
                onOpenChange={(open) => !open && setMoveSource(null)}
            >
                <DialogContent>
                    <DialogTitle>
                        {t('organization.storage.move_dialog_title', {
                            path: moveSource?.path ?? '',
                        })}
                    </DialogTitle>
                    <div className="space-y-4">
                        <p className="text-sm text-muted-foreground">
                            {t('organization.storage.move_description')}
                        </p>
                        <div className="grid gap-2">
                            <Label>
                                {t(
                                    'organization.storage.target_location_label',
                                )}
                            </Label>
                            <Select
                                value={moveTargetId}
                                onValueChange={setMoveTargetId}
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue
                                        placeholder={t(
                                            'organization.storage.select_location_placeholder',
                                        )}
                                    />
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
                            <InputError message={errors.target_node_id} />
                        </div>
                    </div>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button variant="ghost">
                                {t('organization.storage.cancel_button')}
                            </Button>
                        </DialogClose>
                        <Button onClick={submitMove}>
                            {t('organization.storage.move_button')}
                        </Button>
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
    const t = useTranslation();
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
                                    ? `${t(
                                          node.documents_count === 1
                                              ? 'organization.storage.documents_count_one'
                                              : 'organization.storage.documents_count_other',
                                          { count: node.documents_count ?? 0 },
                                      )}${level.capacity !== null ? ` / ${level.capacity}` : ''}`
                                    : t(
                                          node.children.length === 1
                                              ? 'organization.storage.locations_count_one'
                                              : 'organization.storage.locations_count_other',
                                          { count: node.children.length },
                                      )}
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
                                            title={t(
                                                'organization.storage.move_documents_tooltip',
                                            )}
                                            onClick={() => onMove(node)}
                                        >
                                            <ArrowRightLeftIcon className="size-3.5" />
                                        </Button>
                                    )}
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        title={t(
                                            'organization.storage.delete_location_tooltip',
                                        )}
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
