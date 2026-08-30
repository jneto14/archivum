import { Head, router, setLayoutProps, useForm } from '@inertiajs/react';
import { PlusIcon, Trash2Icon } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import DocumentController from '@/actions/App/Http/Controllers/Documents/DocumentController';
import InputError from '@/components/input-error';
import { PageContainer } from '@/components/page-container';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
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
import {
    edit as documentEdit,
    index as documentsIndex,
    show as documentShow,
} from '@/routes/documents';
import tags from '@/routes/tags';

type DocumentType = { id: string; name: string };
type Tag = { id: string; name: string };
type DocumentFormValue = {
    id: string;
    title: string;
    document_date: string | null;
    metadata: Record<string, string> | null;
    document_type: { id: string; name: string; key: string } | null;
    tags: { id: string; name: string }[] | null;
};

type Props = {
    workspaceId: string;
    document: DocumentFormValue | null;
    documentTypes: DocumentType[];
    tags: Tag[];
};

export default function DocumentForm({
    workspaceId,
    document,
    documentTypes,
    tags: workspaceTags,
}: Props) {
    const isEditing = document !== null;

    const t = useTranslation();

    setLayoutProps({
        breadcrumbs: [
            {
                title: t('documents.form.breadcrumb_documents'),
                href: documentsIndex.url(workspaceId),
            },
            isEditing
                ? { title: document.title, href: documentShow.url(document.id) }
                : {
                      title: t('documents.form.breadcrumb_register'),
                      href: documentsIndex.url(workspaceId),
                  },
            ...(isEditing
                ? [
                      {
                          title: t('documents.form.breadcrumb_edit'),
                          href: documentEdit.url(document.id),
                      },
                  ]
                : []),
        ],
    });

    const [metadataPairs, setMetadataPairs] = useState<
        { key: string; value: string }[]
    >(
        Object.entries(document?.metadata ?? {}).map(([key, value]) => ({
            key,
            value,
        })),
    );
    const [newTagName, setNewTagName] = useState('');

    const form = useForm({
        document_type_id: document?.document_type?.id ?? '',
        title: document?.title ?? '',
        document_date: document?.document_date ?? '',
        tag_ids: document?.tags?.map((tag) => tag.id) ?? ([] as string[]),
    });

    const toggleTag = (tagId: string, checked: boolean) => {
        form.setData(
            'tag_ids',
            checked
                ? [...form.data.tag_ids, tagId]
                : form.data.tag_ids.filter((id) => id !== tagId),
        );
    };

    const addMetadataPair = () =>
        setMetadataPairs([...metadataPairs, { key: '', value: '' }]);
    const removeMetadataPair = (index: number) =>
        setMetadataPairs(metadataPairs.filter((_, i) => i !== index));
    const updateMetadataPair = (
        index: number,
        field: 'key' | 'value',
        value: string,
    ) =>
        setMetadataPairs(
            metadataPairs.map((pair, i) =>
                i === index ? { ...pair, [field]: value } : pair,
            ),
        );

    const createTag = () => {
        if (newTagName.trim() === '') {
            return;
        }

        router.post(
            tags.store.url(workspaceId),
            { name: newTagName },
            { preserveScroll: true, onSuccess: () => setNewTagName('') },
        );
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        const metadata = metadataPairs.reduce<Record<string, string>>(
            (acc, pair) => {
                if (pair.key.trim() !== '') {
                    acc[pair.key] = pair.value;
                }

                return acc;
            },
            {},
        );

        form.transform((data) => ({ ...data, metadata }));

        if (isEditing) {
            form.patch(DocumentController.update.url(document.id));
        } else {
            form.post(DocumentController.store.url(workspaceId));
        }
    };

    return (
        <>
            <Head
                title={
                    isEditing
                        ? t('documents.form.page_title_edit')
                        : t('documents.form.page_title_create')
                }
            />

            <PageContainer width="narrow">
                <PageHeader
                    title={
                        isEditing
                            ? t('documents.form.page_title_edit')
                            : t('documents.form.page_title_create')
                    }
                    description={
                        isEditing
                            ? document.title
                            : t('documents.form.description_create')
                    }
                />

                <form onSubmit={submit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {t('documents.form.details_section_title')}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-2 gap-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="document_type_id">
                                        {t(
                                            'documents.form.document_type_label',
                                        )}
                                    </Label>
                                    <Select
                                        value={form.data.document_type_id}
                                        onValueChange={(value) =>
                                            form.setData(
                                                'document_type_id',
                                                value,
                                            )
                                        }
                                    >
                                        <SelectTrigger
                                            id="document_type_id"
                                            className="w-full"
                                        >
                                            <SelectValue
                                                placeholder={t(
                                                    'documents.form.document_type_placeholder',
                                                )}
                                            />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {documentTypes.map((type) => (
                                                <SelectItem
                                                    key={type.id}
                                                    value={type.id}
                                                >
                                                    {type.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError
                                        message={form.errors.document_type_id}
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="document_date">
                                        {t(
                                            'documents.form.document_date_label',
                                        )}
                                    </Label>
                                    <Input
                                        id="document_date"
                                        type="date"
                                        value={form.data.document_date ?? ''}
                                        onChange={(event) =>
                                            form.setData(
                                                'document_date',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <InputError
                                        message={form.errors.document_date}
                                    />
                                </div>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="title">
                                    {t('documents.form.title_label')}
                                </Label>
                                <Input
                                    id="title"
                                    placeholder={t(
                                        'documents.form.title_placeholder',
                                    )}
                                    value={form.data.title}
                                    onChange={(event) =>
                                        form.setData(
                                            'title',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                                <InputError message={form.errors.title} />
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {t('documents.form.tags_section_title')}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="flex flex-col gap-2 rounded-md border p-3">
                                {workspaceTags.length === 0 && (
                                    <p className="text-sm text-muted-foreground">
                                        {t('documents.form.no_tags_message')}
                                    </p>
                                )}
                                {workspaceTags.map((tag) => (
                                    <label
                                        key={tag.id}
                                        className="flex items-center gap-2 text-sm"
                                    >
                                        <Checkbox
                                            checked={form.data.tag_ids.includes(
                                                tag.id,
                                            )}
                                            onCheckedChange={(checked) =>
                                                toggleTag(
                                                    tag.id,
                                                    checked === true,
                                                )
                                            }
                                        />
                                        {tag.name}
                                    </label>
                                ))}
                            </div>
                            <div className="flex items-center gap-2">
                                <Input
                                    placeholder={t(
                                        'documents.form.new_tag_placeholder',
                                    )}
                                    value={newTagName}
                                    onChange={(event) =>
                                        setNewTagName(event.target.value)
                                    }
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={createTag}
                                >
                                    <PlusIcon />{' '}
                                    {t('documents.form.new_tag_button')}
                                </Button>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {t('documents.form.metadata_section_title')}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {metadataPairs.map((pair, index) => (
                                <div
                                    key={index}
                                    className="flex items-center gap-2"
                                >
                                    <Input
                                        placeholder={t(
                                            'documents.form.metadata_key_placeholder',
                                        )}
                                        value={pair.key}
                                        onChange={(event) =>
                                            updateMetadataPair(
                                                index,
                                                'key',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <Input
                                        placeholder={t(
                                            'documents.form.metadata_value_placeholder',
                                        )}
                                        value={pair.value}
                                        onChange={(event) =>
                                            updateMetadataPair(
                                                index,
                                                'value',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            removeMetadataPair(index)
                                        }
                                    >
                                        <Trash2Icon />
                                    </Button>
                                </div>
                            ))}
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={addMetadataPair}
                            >
                                <PlusIcon />{' '}
                                {t('documents.form.add_field_button')}
                            </Button>
                        </CardContent>
                    </Card>

                    <div className="flex items-center justify-end gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() =>
                                router.visit(
                                    isEditing
                                        ? documentShow.url(document.id)
                                        : documentsIndex.url(workspaceId),
                                )
                            }
                        >
                            {t('documents.form.cancel_button')}
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {isEditing
                                ? t('documents.form.submit_button_edit')
                                : t('documents.form.submit_button_create')}
                        </Button>
                    </div>
                </form>

                {isEditing && (
                    <Card className="border-destructive">
                        <CardHeader>
                            <CardTitle>
                                {t('documents.form.delete_section_title')}
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex items-center justify-between gap-4">
                                <p className="text-sm text-muted-foreground">
                                    {t('documents.form.delete_description')}
                                </p>
                                <Dialog>
                                    <DialogTrigger asChild>
                                        <Button variant="destructive" size="sm">
                                            {t(
                                                'documents.form.delete_trigger_button',
                                            )}
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent>
                                        <DialogTitle>
                                            {t(
                                                'documents.form.delete_dialog_title',
                                            )}
                                        </DialogTitle>
                                        <DialogDescription>
                                            {t(
                                                'documents.form.delete_dialog_description',
                                            )}
                                        </DialogDescription>
                                        <DialogFooter className="gap-2">
                                            <DialogClose asChild>
                                                <Button variant="secondary">
                                                    {t(
                                                        'documents.form.delete_dialog_cancel',
                                                    )}
                                                </Button>
                                            </DialogClose>
                                            <Button
                                                variant="destructive"
                                                onClick={() =>
                                                    router.delete(
                                                        DocumentController.destroy.url(
                                                            document.id,
                                                        ),
                                                    )
                                                }
                                            >
                                                {t(
                                                    'documents.form.delete_confirm_button',
                                                )}
                                            </Button>
                                        </DialogFooter>
                                    </DialogContent>
                                </Dialog>
                            </div>
                        </CardContent>
                    </Card>
                )}
            </PageContainer>
        </>
    );
}
