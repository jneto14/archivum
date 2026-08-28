import { Head, router, setLayoutProps, usePage } from '@inertiajs/react';
import { PlusIcon, Trash2Icon } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import {
    edit as schemeEdit,
    index as schemesIndex,
} from '@/routes/organization/schemes';
import nodes from '@/routes/organization/schemes/nodes';
import rules from '@/routes/organization/schemes/rules';

const VALUE_STRATEGY_LABELS: Record<string, string> = {
    manual: 'Manual',
    sequential: 'Sequential',
    alphabetical: 'Alphabetical',
};

type Node = {
    id: string;
    value: string;
    path: string;
    parent_id: string | null;
    documents_count: number | null;
};

type Level = {
    id: string;
    name: string;
    key: string;
    position: number;
    capacity: number | null;
    value_strategy: string;
    is_leaf: boolean;
    nodes: Node[];
};

type Rule = {
    id: string;
    matcher_key: string;
    matcher_value: string;
    target_level: { id: string; name: string };
    preferred_value: string;
};

type Props = {
    scheme: {
        id: string;
        name: string;
        levels: Level[];
        rules: Rule[];
    };
    canManage: boolean;
};

export default function OrganizationSchemeShow({ scheme, canManage }: Props) {
    const { workspace } = usePage().props;
    const [addNodeLevel, setAddNodeLevel] = useState<Level | null>(null);
    const [nodeParentId, setNodeParentId] = useState('');
    const [nodeValue, setNodeValue] = useState('');
    const [ruleDialogOpen, setRuleDialogOpen] = useState(false);
    const [editingRule, setEditingRule] = useState<Rule | null>(null);
    const [ruleForm, setRuleForm] = useState({
        matcher_key: 'document_type',
        matcher_value: '',
        target_level_id: scheme.levels[0]?.id ?? '',
        preferred_value: '',
    });
    setLayoutProps({
        breadcrumbs: [
            {
                title: 'Organization',
                href: workspace ? schemesIndex.url(workspace.id) : '#',
            },
            { title: scheme.name, href: '#' },
        ],
    });

    const openAddNode = (level: Level) => {
        setAddNodeLevel(level);
        setNodeParentId('');
        setNodeValue('');
    };

    const parentLevelFor = (level: Level): Level | null =>
        scheme.levels.find((l) => l.position === level.position - 1) ?? null;

    const submitNode = () => {
        if (addNodeLevel === null) {
            return;
        }

        router.post(
            nodes.store.url(scheme.id),
            {
                level_id: addNodeLevel.id,
                parent_id: nodeParentId || null,
                value: nodeValue.trim() === '' ? null : nodeValue,
            },
            { preserveScroll: true, onSuccess: () => setAddNodeLevel(null) },
        );
    };

    const openAddRule = () => {
        setEditingRule(null);
        setRuleForm({
            matcher_key: 'document_type',
            matcher_value: '',
            target_level_id: scheme.levels[0]?.id ?? '',
            preferred_value: '',
        });
        setRuleDialogOpen(true);
    };

    const openEditRule = (rule: Rule) => {
        setEditingRule(rule);
        setRuleForm({
            matcher_key: rule.matcher_key,
            matcher_value: rule.matcher_value,
            target_level_id: rule.target_level.id,
            preferred_value: rule.preferred_value,
        });
        setRuleDialogOpen(true);
    };

    const submitRule = () => {
        const onSuccess = () => setRuleDialogOpen(false);

        if (editingRule) {
            router.patch(
                rules.update.url({ scheme: scheme.id, rule: editingRule.id }),
                ruleForm,
                { preserveScroll: true, onSuccess },
            );

            return;
        }

        router.post(rules.store.url(scheme.id), ruleForm, {
            preserveScroll: true,
            onSuccess,
        });
    };

    const deleteRule = (rule: Rule) => {
        router.delete(rules.destroy.url({ scheme: scheme.id, rule: rule.id }), {
            preserveScroll: true,
        });
    };

    return (
        <>
            <Head title={scheme.name} />

            <div className="mx-auto max-w-4xl space-y-6 p-6">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {scheme.name}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {scheme.levels.length} level
                            {scheme.levels.length === 1 ? '' : 's'} ·{' '}
                            {scheme.rules.length} rule
                            {scheme.rules.length === 1 ? '' : 's'}
                        </p>
                    </div>
                    {canManage && (
                        <div className="flex items-center gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    router.visit(schemeEdit.url(scheme.id))
                                }
                            >
                                Edit
                            </Button>
                        </div>
                    )}
                </div>

                <div className="space-y-4">
                    {scheme.levels.map((level) => (
                        <Card key={level.id}>
                            <CardHeader className="flex-row items-center justify-between">
                                <div>
                                    <CardTitle>{level.name}</CardTitle>
                                    <div className="mt-1 flex items-center gap-1.5">
                                        <Badge variant="outline">
                                            {level.key}
                                        </Badge>
                                        <Badge variant="secondary">
                                            {
                                                VALUE_STRATEGY_LABELS[
                                                    level.value_strategy
                                                ]
                                            }
                                        </Badge>
                                        <Badge variant="secondary">
                                            {level.capacity !== null
                                                ? `Capacity ${level.capacity}`
                                                : 'Unlimited'}
                                        </Badge>
                                    </div>
                                </div>
                                {canManage && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => openAddNode(level)}
                                    >
                                        <PlusIcon /> Add location
                                    </Button>
                                )}
                            </CardHeader>
                            <CardContent className="space-y-2">
                                {level.nodes.length === 0 && (
                                    <p className="text-sm text-muted-foreground">
                                        No locations yet.
                                    </p>
                                )}
                                {level.nodes.map((node) => {
                                    const pct =
                                        level.capacity !== null &&
                                        node.documents_count !== null
                                            ? Math.round(
                                                  (node.documents_count /
                                                      level.capacity) *
                                                      100,
                                              )
                                            : null;

                                    return (
                                        <div
                                            key={node.id}
                                            className="flex items-center gap-3 rounded-md border p-2"
                                        >
                                            <div className="min-w-0 flex-1">
                                                <div className="truncate font-mono text-sm font-medium">
                                                    {node.path}
                                                </div>
                                                {level.is_leaf && (
                                                    <div className="text-xs text-muted-foreground">
                                                        {node.documents_count}{' '}
                                                        document
                                                        {node.documents_count ===
                                                        1
                                                            ? ''
                                                            : 's'}
                                                        {level.capacity !== null
                                                            ? ` / ${level.capacity}`
                                                            : ''}
                                                    </div>
                                                )}
                                            </div>
                                            {pct !== null && (
                                                <div className="w-24">
                                                    <Progress value={pct} />
                                                </div>
                                            )}
                                        </div>
                                    );
                                })}
                            </CardContent>
                        </Card>
                    ))}
                </div>

                <Card>
                    <CardHeader className="flex-row items-center justify-between">
                        <CardTitle>Matching rules</CardTitle>
                        {canManage && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={openAddRule}
                            >
                                <PlusIcon /> Add rule
                            </Button>
                        )}
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {scheme.rules.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                No rules yet — documents will file into the
                                first available location.
                            </p>
                        )}
                        {scheme.rules.map((rule) => (
                            <div
                                key={rule.id}
                                className="flex items-center gap-3 rounded-md border p-2 text-sm"
                            >
                                <div className="min-w-0 flex-1">
                                    <span className="font-mono">
                                        {rule.matcher_key} ={' '}
                                        {rule.matcher_value}
                                    </span>
                                    <span className="mx-2 text-muted-foreground">
                                        →
                                    </span>
                                    <span>{rule.target_level.name}</span>
                                    <span className="mx-1 text-muted-foreground">
                                        :
                                    </span>
                                    <span className="font-mono">
                                        {rule.preferred_value}
                                    </span>
                                </div>
                                {canManage && (
                                    <div className="flex items-center gap-1">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => openEditRule(rule)}
                                        >
                                            Edit
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => deleteRule(rule)}
                                        >
                                            <Trash2Icon />
                                        </Button>
                                    </div>
                                )}
                            </div>
                        ))}
                    </CardContent>
                </Card>
            </div>

            <Dialog
                open={addNodeLevel !== null}
                onOpenChange={(open) => !open && setAddNodeLevel(null)}
            >
                <DialogContent>
                    <DialogTitle>
                        Add location to {addNodeLevel?.name}
                    </DialogTitle>
                    <div className="space-y-4">
                        {addNodeLevel &&
                            parentLevelFor(addNodeLevel) !== null && (
                                <div className="grid gap-2">
                                    <Label>Parent location</Label>
                                    <Select
                                        value={nodeParentId}
                                        onValueChange={setNodeParentId}
                                    >
                                        <SelectTrigger className="w-full">
                                            <SelectValue placeholder="Select a parent" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {parentLevelFor(
                                                addNodeLevel,
                                            )?.nodes.map((parent) => (
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
                        {addNodeLevel?.value_strategy === 'manual' && (
                            <div className="grid gap-2">
                                <Label htmlFor="node_value">Value</Label>
                                <Input
                                    id="node_value"
                                    value={nodeValue}
                                    onChange={(event) =>
                                        setNodeValue(event.target.value)
                                    }
                                    placeholder="A"
                                />
                            </div>
                        )}
                        {addNodeLevel &&
                            addNodeLevel.value_strategy !== 'manual' && (
                                <p className="text-sm text-muted-foreground">
                                    The value will be generated automatically (
                                    {
                                        VALUE_STRATEGY_LABELS[
                                            addNodeLevel.value_strategy
                                        ]
                                    }
                                    ).
                                </p>
                            )}
                    </div>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button variant="ghost">Cancel</Button>
                        </DialogClose>
                        <Button onClick={submitNode}>Add</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={ruleDialogOpen} onOpenChange={setRuleDialogOpen}>
                <DialogContent>
                    <DialogTitle>
                        {editingRule ? 'Edit rule' : 'Add rule'}
                    </DialogTitle>
                    <div className="space-y-4">
                        <div className="grid grid-cols-2 gap-4">
                            <div className="grid gap-2">
                                <Label htmlFor="matcher_key">Matcher key</Label>
                                <Input
                                    id="matcher_key"
                                    value={ruleForm.matcher_key}
                                    onChange={(event) =>
                                        setRuleForm({
                                            ...ruleForm,
                                            matcher_key: event.target.value,
                                        })
                                    }
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="matcher_value">
                                    Matcher value
                                </Label>
                                <Input
                                    id="matcher_value"
                                    value={ruleForm.matcher_value}
                                    onChange={(event) =>
                                        setRuleForm({
                                            ...ruleForm,
                                            matcher_value: event.target.value,
                                        })
                                    }
                                />
                            </div>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="target_level_id">
                                Target level
                            </Label>
                            <Select
                                value={ruleForm.target_level_id}
                                onValueChange={(value) =>
                                    setRuleForm({
                                        ...ruleForm,
                                        target_level_id: value,
                                    })
                                }
                            >
                                <SelectTrigger
                                    id="target_level_id"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {scheme.levels.map((level) => (
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
                        <div className="grid gap-2">
                            <Label htmlFor="preferred_value">
                                Preferred value
                            </Label>
                            <Input
                                id="preferred_value"
                                value={ruleForm.preferred_value}
                                onChange={(event) =>
                                    setRuleForm({
                                        ...ruleForm,
                                        preferred_value: event.target.value,
                                    })
                                }
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button variant="ghost">Cancel</Button>
                        </DialogClose>
                        <Button onClick={submitRule}>
                            {editingRule ? 'Save changes' : 'Add rule'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
