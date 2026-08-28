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
import { useTranslation } from '@/hooks/use-translation';
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

export default function OrganizationSchemeShow({
    scheme,
    canManage,
    resultingPath,
}: Props) {
    const t = useTranslation();
    const strategyDescriptions: Record<string, string> = {
        manual: t('organization.show.strategy_manual'),
        sequential: t('organization.show.strategy_sequential'),
        alphabetical: t('organization.show.strategy_alphabetical'),
    };
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
            { title: t('organization.show.title'), href: '#' },
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
            <Head title={t('organization.show.title')} />

            <div className="mx-auto max-w-5xl space-y-6 p-6">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {t('organization.show.title')}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {t('organization.show.subtitle')}
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
                            {t('organization.show.edit_button')}
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
                                        {scheme.levels.length === 1
                                            ? t(
                                                  'organization.show.active_scheme_levels_one',
                                                  {
                                                      count: scheme.levels
                                                          .length,
                                                  },
                                              )
                                            : t(
                                                  'organization.show.active_scheme_levels_other',
                                                  {
                                                      count: scheme.levels
                                                          .length,
                                                  },
                                              )}
                                    </p>
                                </div>
                                <Badge variant="secondary">
                                    {t('organization.show.active_badge')}
                                </Badge>
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
                                                {t(
                                                    'organization.show.level_key_label',
                                                )}{' '}
                                                {level.key}
                                            </span>
                                        </span>
                                        <span className="flex-1 text-xs text-muted-foreground">
                                            {
                                                strategyDescriptions[
                                                    level.value_strategy
                                                ]
                                            }
                                        </span>
                                        <span className="ml-auto flex-none text-xs text-muted-foreground">
                                            {level.capacity !== null
                                                ? t(
                                                      'organization.show.capacity_label',
                                                      { count: level.capacity },
                                                  )
                                                : t(
                                                      'organization.show.unlimited_label',
                                                  )}
                                        </span>
                                    </div>
                                ))}
                            </CardContent>
                        </Card>

                        <Card className="overflow-hidden py-0">
                            <CardHeader className="flex-row items-center justify-between border-b py-4">
                                <div>
                                    <CardTitle>
                                        {t('organization.show.rules_heading')}
                                    </CardTitle>
                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                        {t('organization.show.rules_subtitle')}
                                    </p>
                                </div>
                                {canManage && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={openAddRule}
                                    >
                                        <PlusIcon />{' '}
                                        {t('organization.show.add_rule_button')}
                                    </Button>
                                )}
                            </CardHeader>
                            <CardContent className="space-y-0 p-0">
                                {scheme.rules.length === 0 && (
                                    <p className="p-4 text-sm text-muted-foreground">
                                        {t(
                                            'organization.show.no_rules_empty_state',
                                        )}
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
                                                    {t(
                                                        'organization.show.edit_button',
                                                    )}
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
                            <CardTitle>
                                {t('organization.show.resulting_path_heading')}
                            </CardTitle>
                            <p className="text-xs text-muted-foreground">
                                {t('organization.show.resulting_path_subtitle')}
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
                                    {t('organization.show.full_location_label')}
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
                        {editingRule
                            ? t('organization.show.edit_rule_dialog_title')
                            : t('organization.show.add_rule_button')}
                    </DialogTitle>
                    <div className="space-y-4">
                        <div className="grid grid-cols-2 gap-4">
                            <div className="grid gap-2">
                                <Label htmlFor="matcher_key">
                                    {t('organization.show.matcher_key_label')}
                                </Label>
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
                                    {t('organization.show.matcher_value_label')}
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
                                {t('organization.show.target_level_label')}
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
                                {t('organization.show.preferred_value_label')}
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
                            <Button variant="ghost">
                                {t('organization.show.cancel_button')}
                            </Button>
                        </DialogClose>
                        <Button onClick={submitRule}>
                            {editingRule
                                ? t('organization.show.save_changes_button')
                                : t('organization.show.add_rule_button')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
