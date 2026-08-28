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
import { dashboard } from '@/routes';
import { show as schemeShow } from '@/routes/organization/schemes';

const VALUE_STRATEGIES = [
    { value: 'manual', label: 'Manual' },
    { value: 'sequential', label: 'Sequential (auto: 001, 002…)' },
    { value: 'alphabetical', label: 'Alphabetical (auto: A, B…)' },
];

type SchemeFormValue = { id: string; name: string };

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
                            ? 'Levels can only be defined when a scheme is created.'
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
