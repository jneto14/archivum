import { Head, router, setLayoutProps, useForm } from '@inertiajs/react';
import { PlusIcon, Trash2Icon } from 'lucide-react';
import type { FormEvent } from 'react';
import OrganizationSchemeController from '@/actions/App/Http/Controllers/Organization/OrganizationSchemeController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import { dashboard } from '@/routes';
import { show as schemeShow } from '@/routes/organization/schemes';
import levelActions from '@/routes/organization/schemes/levels';

const VALUE_STRATEGIES = [
    { value: 'manual', label: 'Manual' },
    { value: 'sequential', label: 'Sequential (auto: 001, 002…)' },
    { value: 'alphabetical', label: 'Alphabetical (auto: A, B…)' },
];

const STRATEGY_DESCRIPTIONS: Record<string, string> = {
    manual: 'Value entered manually for each location',
    sequential: 'Auto-generated: 001, 002…',
    alphabetical: 'Auto-generated: A, B…',
};

type LevelRow = {
    id: string;
    name: string;
    key: string;
    position: number;
    capacity: number | null;
    value_strategy: string;
    has_nodes: boolean;
};

type SchemeFormValue = { id: string; name: string; levels: LevelRow[] };

type LevelDraft = {
    name: string;
    key: string;
    capacity: string;
    value_strategy: string;
};

type Props = {
    workspaceId: string;
    scheme: SchemeFormValue | null;
};

export default function OrganizationSchemeForm({ workspaceId, scheme }: Props) {
    const isEditing = scheme !== null;

    setLayoutProps({
        breadcrumbs: [
            isEditing
                ? { title: scheme.name, href: schemeShow.url(scheme.id) }
                : { title: 'Organization scheme', href: '#' },
            { title: isEditing ? 'Edit' : 'New scheme', href: '#' },
        ],
    });

    const form = useForm<{
        name: string;
        levels: LevelDraft[];
    }>({
        name: scheme?.name ?? '',
        levels: [
            { name: '', key: '', capacity: '', value_strategy: 'sequential' },
        ],
    });

    const addLevel = () =>
        form.setData('levels', [
            ...form.data.levels,
            { name: '', key: '', capacity: '', value_strategy: 'sequential' },
        ]);

    const removeLevel = (index: number) =>
        form.setData(
            'levels',
            form.data.levels.filter((_, i) => i !== index),
        );

    const updateLevel = (
        index: number,
        field: keyof LevelDraft,
        value: string,
    ) =>
        form.setData(
            'levels',
            form.data.levels.map((level, i) =>
                i === index ? { ...level, [field]: value } : level,
            ),
        );

    const newLevelForm = useForm<LevelDraft>({
        name: '',
        key: '',
        capacity: '',
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
                        ? 'Edit organization scheme'
                        : 'New organization scheme'
                }
            />

            <div className="mx-auto max-w-3xl space-y-6 p-6">
                <Heading
                    title={
                        isEditing
                            ? 'Edit organization scheme'
                            : 'New organization scheme'
                    }
                    description={
                        isEditing
                            ? 'New levels are always appended at the end; only the last, empty level can be removed.'
                            : 'Define how documents will be physically filed, level by level.'
                    }
                />

                <form onSubmit={submit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Scheme details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-2">
                                <Label htmlFor="name">Name</Label>
                                <Input
                                    id="name"
                                    placeholder="Traditional Archive"
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
                                <CardTitle>Levels</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
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
                                            <span className="flex-none text-xs text-muted-foreground">
                                                {level.capacity !== null
                                                    ? `Capacity ${level.capacity}`
                                                    : 'Unlimited'}
                                            </span>
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
                                                            ? 'This level still has locations — remove them first.'
                                                            : 'Only the last level can be removed.'}
                                                    </TooltipContent>
                                                </Tooltip>
                                            )}
                                        </div>
                                    );
                                })}

                                <div className="grid grid-cols-[1fr_1fr_auto_1fr_auto] items-end gap-2 rounded-md border border-dashed p-3">
                                    <div className="grid gap-2">
                                        <Label>Name</Label>
                                        <Input
                                            placeholder="Box"
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
                                        <Label>Key</Label>
                                        <Input
                                            placeholder="box"
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
                                        <Label>Capacity</Label>
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
                                        <Label>Value strategy</Label>
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
                                                {VALUE_STRATEGIES.map(
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
                                    <Button
                                        type="button"
                                        size="sm"
                                        onClick={submitNewLevel}
                                        disabled={newLevelForm.processing}
                                    >
                                        <PlusIcon /> Add level
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    {!isEditing && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Levels</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {form.data.levels.map((level, index) => (
                                    <div
                                        key={index}
                                        className="grid grid-cols-[1fr_1fr_auto_1fr_auto] items-end gap-2 rounded-md border p-3"
                                    >
                                        <div className="grid gap-2">
                                            <Label>Name</Label>
                                            <Input
                                                placeholder="Cover"
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
                                            <Label>Key</Label>
                                            <Input
                                                placeholder="cover"
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
                                            <Label>Capacity</Label>
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
                                            <Label>Value strategy</Label>
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
                                                    {VALUE_STRATEGIES.map(
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
                                    </div>
                                ))}
                                <InputError message={form.errors.levels} />
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={addLevel}
                                >
                                    <PlusIcon /> Add level
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
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {isEditing ? 'Save changes' : 'Create scheme'}
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}
