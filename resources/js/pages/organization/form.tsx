import { Head, router, setLayoutProps, useForm } from '@inertiajs/react';
import { PlusIcon, Trash2Icon } from 'lucide-react';
import type { FormEvent } from 'react';
import OrganizationSchemeController from '@/actions/App/Http/Controllers/Organization/OrganizationSchemeController';
import InputError from '@/components/input-error';
import { PageContainer } from '@/components/page-container';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useTranslation } from '@/hooks/use-translation';
import { randomId } from '@/lib/utils';
import { dashboard } from '@/routes';
import { show as schemeShow } from '@/routes/organization/schemes';
import levelActions from '@/routes/organization/schemes/levels';

type LevelRow = {
    id: string;
    name: string;
    key: string;
    position: number;
    capacity: number | null;
    has_printable_label: boolean;
    value_strategy: string;
    has_nodes: boolean;
};

type SchemeFormValue = { id: string; name: string; levels: LevelRow[] };

/** The fields a level actually submits. */
type LevelFields = {
    name: string;
    key: string;
    capacity: string;
    has_printable_label: boolean;
    value_strategy: string;
};

/**
 * A level as one editable row in the form.
 *
 * The id is client-only and stripped by the submit transform. These rows are
 * added and removed by hand, so keying them by array index made React reuse a
 * removed row's DOM for the row that slid up into its place — the caret and
 * focus stayed where they were while the values moved beneath them.
 *
 * The first row's id is a literal rather than a generated one. `useForm` keeps
 * its initial value only on the first render, so a generated id would in fact
 * be stable — but the expression still runs on every render, and twice per
 * render under Strict Mode. Depending on `useState` throwing the extra values
 * away is depending on an implementation detail, and a row that exists from the
 * start has no need of a generated identity anyway.
 */
type LevelDraft = LevelFields & { id: string };

type Props = {
    workspaceId: string;
    scheme: SchemeFormValue | null;
};

export default function OrganizationSchemeForm({ workspaceId, scheme }: Props) {
    const t = useTranslation();

    const valueStrategies = [
        {
            value: 'manual',
            label: t('organization.form.strategy_manual_label'),
        },
        {
            value: 'sequential',
            label: t('organization.form.strategy_sequential_label'),
        },
        {
            value: 'alphabetical',
            label: t('organization.form.strategy_alphabetical_label'),
        },
    ];

    const strategyDescriptions: Record<string, string> = {
        manual: t('organization.form.strategy_manual_description'),
        sequential: t('organization.form.strategy_sequential_description'),
        alphabetical: t('organization.form.strategy_alphabetical_description'),
    };

    const isEditing = scheme !== null;

    setLayoutProps({
        breadcrumbs: [
            isEditing
                ? { title: scheme.name, href: schemeShow.url(scheme.id) }
                : {
                      title: t('organization.form.breadcrumb_default_title'),
                      href: '#',
                  },
            {
                title: isEditing
                    ? t('organization.form.breadcrumb_edit')
                    : t('organization.form.breadcrumb_new'),
                href: '#',
            },
        ],
    });

    const form = useForm<{
        name: string;
        levels: LevelDraft[];
    }>({
        name: scheme?.name ?? '',
        levels: [
            {
                id: 'level-0',
                name: '',
                key: '',
                capacity: '',
                has_printable_label: false,
                value_strategy: 'sequential',
            },
        ],
    });

    const addLevel = () =>
        form.setData('levels', [
            ...form.data.levels,
            {
                id: randomId(),
                name: '',
                key: '',
                capacity: '',
                has_printable_label: false,
                value_strategy: 'sequential',
            },
        ]);

    const removeLevel = (index: number) =>
        form.setData(
            'levels',
            form.data.levels.filter((_, i) => i !== index),
        );

    const updateLevel = (
        index: number,
        field: keyof LevelDraft,
        value: string | boolean,
    ) =>
        form.setData(
            'levels',
            form.data.levels.map((level, i) =>
                i === index ? { ...level, [field]: value } : level,
            ),
        );

    const newLevelForm = useForm<LevelFields>({
        name: '',
        key: '',
        capacity: '',
        has_printable_label: false,
        value_strategy: 'sequential',
    });

    const submitNewLevel = () => {
        if (!isEditing) {
            return;
        }

        newLevelForm.transform((data) => ({
            name: data.name,
            key: data.key,
            capacity: data.capacity.trim() === '' ? null : data.capacity,
            has_printable_label: data.has_printable_label,
            value_strategy: data.value_strategy,
        }));
        newLevelForm.post(levelActions.store.url(scheme.id), {
            preserveScroll: true,
            onSuccess: () => newLevelForm.reset(),
        });
    };

    const lastLevel = isEditing
        ? scheme.levels[scheme.levels.length - 1]
        : undefined;

    const toggleLevelLabels = (level: LevelRow, enabled: boolean) => {
        if (!isEditing) {
            return;
        }

        router.patch(
            levelActions.update.url({ scheme: scheme.id, level: level.id }),
            { has_printable_label: enabled },
            { preserveScroll: true },
        );
    };

    const deleteLevel = (level: LevelRow) => {
        if (!isEditing) {
            return;
        }

        router.delete(
            levelActions.destroy.url({ scheme: scheme.id, level: level.id }),
            { preserveScroll: true },
        );
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (isEditing) {
            form.transform((data) => ({ name: data.name }));
            form.patch(OrganizationSchemeController.update.url(scheme.id));

            return;
        }

        form.transform((data) => ({
            name: data.name,
            levels: data.levels.map((level) => ({
                name: level.name,
                key: level.key,
                capacity: level.capacity.trim() === '' ? null : level.capacity,
                has_printable_label: level.has_printable_label,
                value_strategy: level.value_strategy,
            })),
        }));
        form.post(OrganizationSchemeController.store.url(workspaceId));
    };

    return (
        <>
            <Head
                title={
                    isEditing
                        ? t('organization.form.edit_title')
                        : t('organization.form.create_title')
                }
            />

            <PageContainer width="narrow">
                <PageHeader
                    title={
                        isEditing
                            ? t('organization.form.edit_title')
                            : t('organization.form.create_title')
                    }
                    description={
                        isEditing
                            ? t('organization.form.edit_description')
                            : t('organization.form.create_description')
                    }
                />

                <form onSubmit={submit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {t('organization.form.scheme_details_title')}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-2">
                                <Label htmlFor="name">
                                    {t('organization.form.name_label')}
                                </Label>
                                <Input
                                    id="name"
                                    placeholder={t(
                                        'organization.form.name_placeholder',
                                    )}
                                    value={form.data.name}
                                    onChange={(event) =>
                                        form.setData('name', event.target.value)
                                    }
                                    required
                                />
                                <InputError message={form.errors.name} />
                            </div>
                        </CardContent>
                    </Card>

                    {isEditing && (
                        <Card>
                            <CardHeader>
                                <CardTitle>
                                    {t('organization.form.levels_title')}
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="@container/levels space-y-4">
                                {scheme.levels.map((level) => {
                                    const canDelete =
                                        level.id === lastLevel?.id &&
                                        !level.has_nodes;

                                    const deleteButton = (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            disabled={!canDelete}
                                            onClick={() => deleteLevel(level)}
                                        >
                                            <Trash2Icon />
                                        </Button>
                                    );

                                    return (
                                        <div
                                            key={level.id}
                                            className="flex flex-wrap items-center gap-3 rounded-md border p-3"
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
                                                        'organization.form.level_key_display',
                                                        { key: level.key },
                                                    )}
                                                </span>
                                            </span>
                                            <span className="min-w-0 basis-full text-xs text-muted-foreground @2xl/levels:flex-1 @2xl/levels:basis-auto">
                                                {
                                                    strategyDescriptions[
                                                        level.value_strategy
                                                    ]
                                                }
                                            </span>
                                            <span className="flex-none text-xs text-muted-foreground">
                                                {level.capacity !== null
                                                    ? t(
                                                          'organization.form.level_capacity_value',
                                                          {
                                                              capacity:
                                                                  level.capacity,
                                                          },
                                                      )
                                                    : t(
                                                          'organization.form.level_capacity_unlimited',
                                                      )}
                                            </span>
                                            <label className="flex flex-none items-center gap-2 text-xs text-muted-foreground">
                                                <Checkbox
                                                    checked={
                                                        level.has_printable_label
                                                    }
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        toggleLevelLabels(
                                                            level,
                                                            checked === true,
                                                        )
                                                    }
                                                />
                                                {t(
                                                    'organization.form.level_labels_label',
                                                )}
                                            </label>
                                            {canDelete ? (
                                                deleteButton
                                            ) : (
                                                <Tooltip>
                                                    <TooltipTrigger asChild>
                                                        <span tabIndex={0}>
                                                            {deleteButton}
                                                        </span>
                                                    </TooltipTrigger>
                                                    <TooltipContent>
                                                        {level.has_nodes
                                                            ? t(
                                                                  'organization.form.level_delete_blocked_has_nodes',
                                                              )
                                                            : t(
                                                                  'organization.form.level_delete_blocked_not_last',
                                                              )}
                                                    </TooltipContent>
                                                </Tooltip>
                                            )}
                                        </div>
                                    );
                                })}

                                <div className="grid grid-cols-1 items-end gap-3 rounded-md border border-dashed p-3 @2xl/levels:grid-cols-[1fr_1fr_auto_1fr_auto] @2xl/levels:gap-2">
                                    <div className="grid gap-2">
                                        <Label>
                                            {t(
                                                'organization.form.level_name_label',
                                            )}
                                        </Label>
                                        <Input
                                            placeholder={t(
                                                'organization.form.new_level_name_placeholder',
                                            )}
                                            value={newLevelForm.data.name}
                                            onChange={(event) =>
                                                newLevelForm.setData(
                                                    'name',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        <InputError
                                            message={newLevelForm.errors.name}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label>
                                            {t(
                                                'organization.form.level_key_label',
                                            )}
                                        </Label>
                                        <Input
                                            placeholder={t(
                                                'organization.form.new_level_key_placeholder',
                                            )}
                                            value={newLevelForm.data.key}
                                            onChange={(event) =>
                                                newLevelForm.setData(
                                                    'key',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        <InputError
                                            message={newLevelForm.errors.key}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label>
                                            {t(
                                                'organization.form.level_capacity_label',
                                            )}
                                        </Label>
                                        <Input
                                            type="number"
                                            min={1}
                                            max={
                                                newLevelForm.data
                                                    .value_strategy ===
                                                'alphabetical'
                                                    ? 26
                                                    : undefined
                                            }
                                            placeholder={
                                                newLevelForm.data
                                                    .value_strategy ===
                                                'alphabetical'
                                                    ? '26'
                                                    : '∞'
                                            }
                                            className="w-20"
                                            value={newLevelForm.data.capacity}
                                            onChange={(event) =>
                                                newLevelForm.setData(
                                                    'capacity',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        <InputError
                                            message={
                                                newLevelForm.errors.capacity
                                            }
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label>
                                            {t(
                                                'organization.form.level_value_strategy_label',
                                            )}
                                        </Label>
                                        <Select
                                            value={
                                                newLevelForm.data.value_strategy
                                            }
                                            onValueChange={(value) =>
                                                newLevelForm.setData(
                                                    'value_strategy',
                                                    value,
                                                )
                                            }
                                        >
                                            <SelectTrigger className="w-full">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {valueStrategies.map(
                                                    (strategy) => (
                                                        <SelectItem
                                                            key={strategy.value}
                                                            value={
                                                                strategy.value
                                                            }
                                                        >
                                                            {strategy.label}
                                                        </SelectItem>
                                                    ),
                                                )}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <label className="flex items-center gap-2 text-sm">
                                        <Checkbox
                                            checked={
                                                newLevelForm.data
                                                    .has_printable_label
                                            }
                                            onCheckedChange={(checked) =>
                                                newLevelForm.setData(
                                                    'has_printable_label',
                                                    checked === true,
                                                )
                                            }
                                        />
                                        {t(
                                            'organization.form.level_labels_label',
                                        )}
                                    </label>
                                    <Button
                                        type="button"
                                        size="sm"
                                        onClick={submitNewLevel}
                                        disabled={newLevelForm.processing}
                                    >
                                        <PlusIcon />{' '}
                                        {t(
                                            'organization.form.add_level_button',
                                        )}
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    {!isEditing && (
                        <Card>
                            <CardHeader>
                                <CardTitle>
                                    {t('organization.form.levels_title')}
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="@container/levels space-y-4">
                                {form.data.levels.map((level, index) => (
                                    <div
                                        key={level.id}
                                        className="grid grid-cols-1 items-end gap-3 rounded-md border p-3 @2xl/levels:grid-cols-[1fr_1fr_auto_1fr_auto] @2xl/levels:gap-2"
                                    >
                                        <div className="grid gap-2">
                                            <Label>
                                                {t(
                                                    'organization.form.level_name_label',
                                                )}
                                            </Label>
                                            <Input
                                                placeholder={t(
                                                    'organization.form.level_name_placeholder',
                                                )}
                                                value={level.name}
                                                onChange={(event) =>
                                                    updateLevel(
                                                        index,
                                                        'name',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label>
                                                {t(
                                                    'organization.form.level_key_label',
                                                )}
                                            </Label>
                                            <Input
                                                placeholder={t(
                                                    'organization.form.level_key_placeholder',
                                                )}
                                                value={level.key}
                                                onChange={(event) =>
                                                    updateLevel(
                                                        index,
                                                        'key',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label>
                                                {t(
                                                    'organization.form.level_capacity_label',
                                                )}
                                            </Label>
                                            <Input
                                                type="number"
                                                min={1}
                                                max={
                                                    level.value_strategy ===
                                                    'alphabetical'
                                                        ? 26
                                                        : undefined
                                                }
                                                placeholder={
                                                    level.value_strategy ===
                                                    'alphabetical'
                                                        ? '26'
                                                        : '∞'
                                                }
                                                className="w-20"
                                                value={level.capacity}
                                                onChange={(event) =>
                                                    updateLevel(
                                                        index,
                                                        'capacity',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label>
                                                {t(
                                                    'organization.form.level_value_strategy_label',
                                                )}
                                            </Label>
                                            <Select
                                                value={level.value_strategy}
                                                onValueChange={(value) =>
                                                    updateLevel(
                                                        index,
                                                        'value_strategy',
                                                        value,
                                                    )
                                                }
                                            >
                                                <SelectTrigger className="w-full">
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {valueStrategies.map(
                                                        (strategy) => (
                                                            <SelectItem
                                                                key={
                                                                    strategy.value
                                                                }
                                                                value={
                                                                    strategy.value
                                                                }
                                                            >
                                                                {strategy.label}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            disabled={
                                                form.data.levels.length === 1
                                            }
                                            onClick={() => removeLevel(index)}
                                        >
                                            <Trash2Icon />
                                        </Button>
                                        <label className="col-span-full flex items-center gap-2 text-sm">
                                            <Checkbox
                                                checked={
                                                    level.has_printable_label
                                                }
                                                onCheckedChange={(checked) =>
                                                    updateLevel(
                                                        index,
                                                        'has_printable_label',
                                                        checked === true,
                                                    )
                                                }
                                            />
                                            {t(
                                                'organization.form.level_labels_label',
                                            )}
                                        </label>
                                    </div>
                                ))}
                                <InputError message={form.errors.levels} />
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={addLevel}
                                >
                                    <PlusIcon />{' '}
                                    {t('organization.form.add_level_button')}
                                </Button>
                            </CardContent>
                        </Card>
                    )}

                    <div className="flex items-center justify-end gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() =>
                                router.visit(
                                    isEditing
                                        ? schemeShow.url(scheme.id)
                                        : dashboard(),
                                )
                            }
                        >
                            {t('organization.form.cancel_button')}
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {isEditing
                                ? t('organization.form.save_changes_button')
                                : t('organization.form.create_scheme_button')}
                        </Button>
                    </div>
                </form>
            </PageContainer>
        </>
    );
}
