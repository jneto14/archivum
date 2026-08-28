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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { edit as schemeEdit } from '@/routes/organization/schemes';
import rules from '@/routes/organization/schemes/rules';

type Level = {
    id: string;
    name: string;
    key: string;
    position: number;
    capacity: number | null;
    value_strategy: string;
    is_leaf: boolean;
};

type Rule = {
    id: string;
    matcher_key: string;
    matcher_value: string;
    target_level: { id: string; name: string };
    preferred_value: string;
};

type ResultingPath = {
    levels: { level_id: string; level_name: string; sample: string | null }[];
    path: string;
};

type Props = {
    scheme: {
        id: string;
        name: string;
        levels: Level[];
        rules: Rule[];
    };
    canManage: boolean;
    resultingPath: ResultingPath;
};

const STRATEGY_DESCRIPTIONS: Record<string, string> = {
    manual: 'Value entered manually for each location',
    sequential: 'Auto-generated: 001, 002…',
    alphabetical: 'Auto-generated: A, B…',
};

export default function OrganizationSchemeShow({
    scheme,
    canManage,
    resultingPath,
}: Props) {
    const { workspace } = usePage().props;
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
            { title: workspace?.name ?? '', href: '#' },
            { title: 'Organization scheme', href: '#' },
        ],
    });

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
            <Head title="Organization scheme" />

            <div className="mx-auto max-w-5xl space-y-6 p-6">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Organization scheme
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Levels define the physical hierarchy. Nothing here
                            is hard-coded in the application.
                        </p>
                    </div>
                    {canManage && (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                router.visit(schemeEdit.url(scheme.id))
                            }
                        >
                            Edit
                        </Button>
                    )}
                </div>

                <div className="grid items-start gap-4 lg:grid-cols-[1.6fr_1fr]">
                    <div className="space-y-4">
                        <Card className="overflow-hidden py-0">
                            <CardHeader className="flex-row items-center justify-between border-b py-4">
                                <div>
                                    <CardTitle>{scheme.name}</CardTitle>
                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                        Active scheme · {scheme.levels.length}{' '}
                                        level
                                        {scheme.levels.length === 1 ? '' : 's'}
                                    </p>
                                </div>
                                <Badge variant="secondary">Active</Badge>
                            </CardHeader>
                            <CardContent className="space-y-0 p-0">
                                {scheme.levels.map((level) => (
                                    <div
                                        key={level.id}
                                        className="flex flex-wrap items-center gap-3 border-b p-4 last:border-b-0"
                                    >
                                        <span className="flex size-6 flex-none items-center justify-center rounded-md bg-secondary font-mono text-xs font-semibold">
                                            {level.position}
                                        </span>
                                        <span className="min-w-30 flex-1 space-y-0.5">
                                            <span className="block text-sm font-medium">
                                                {level.name}
                                            </span>
                                            <span className="block font-mono text-xs text-muted-foreground">
                                                key: {level.key}
                                            </span>
                                        </span>
                                        <span className="flex-1 text-xs text-muted-foreground">
                                            {
                                                STRATEGY_DESCRIPTIONS[
                                                    level.value_strategy
                                                ]
                                            }
                                        </span>
                                        <span className="ml-auto flex-none text-xs text-muted-foreground">
                                            {level.capacity !== null
                                                ? `Capacity ${level.capacity}`
                                                : 'Unlimited'}
                                        </span>
                                    </div>
                                ))}
                            </CardContent>
                        </Card>

                        <Card className="overflow-hidden py-0">
                            <CardHeader className="flex-row items-center justify-between border-b py-4">
                                <div>
                                    <CardTitle>Organization rules</CardTitle>
                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                        Recommendations, not constraints.
                                    </p>
                                </div>
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
                            <CardContent className="space-y-0 p-0">
                                {scheme.rules.length === 0 && (
                                    <p className="p-4 text-sm text-muted-foreground">
                                        No rules yet — documents will file into
                                        the first available location.
                                    </p>
                                )}
                                {scheme.rules.map((rule) => (
                                    <div
                                        key={rule.id}
                                        className="flex items-center gap-3 border-b p-4 text-sm last:border-b-0"
                                    >
                                        <Badge variant="secondary">
                                            {rule.matcher_value}
                                        </Badge>
                                        <span className="text-muted-foreground">
                                            →
                                        </span>
                                        <span className="flex-1 font-mono text-xs">
                                            {rule.target_level.name}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {rule.preferred_value}
                                        </span>
                                        {canManage && (
                                            <div className="flex items-center gap-1">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        openEditRule(rule)
                                                    }
                                                >
                                                    Edit
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        deleteRule(rule)
                                                    }
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

                    <Card className="sticky top-6">
                        <CardHeader>
                            <CardTitle>Resulting path</CardTitle>
                            <p className="text-xs text-muted-foreground">
                                Preview using the first matching rule per level.
                            </p>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {resultingPath.levels.map((level) => (
                                <div
                                    key={level.level_id}
                                    className="flex items-center justify-between gap-3 rounded-md border bg-muted px-3 py-2"
                                >
                                    <span className="text-xs text-muted-foreground">
                                        {level.level_name}
                                    </span>
                                    <span className="font-mono text-sm font-medium">
                                        {level.sample ?? '···'}
                                    </span>
                                </div>
                            ))}
                            <div className="border-t pt-4 text-center">
                                <p className="mb-1.5 text-xs text-muted-foreground">
                                    Full location
                                </p>
                                <p className="font-mono text-lg font-semibold">
                                    {resultingPath.path}
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>

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
