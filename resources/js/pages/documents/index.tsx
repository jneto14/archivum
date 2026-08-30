import { Head, router, setLayoutProps, usePage } from '@inertiajs/react';
import { FileTextIcon } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useTranslation } from '@/hooks/use-translation';
import {
    create as documentCreate,
    index as documentsIndex,
    show as documentShow,
} from '@/routes/documents';
import { store as startExport } from '@/routes/workspaces/tasks';

const ALL = '__all__';

type DocumentRow = {
    id: string;
    title: string;
    document_date: string | null;
    document_type: { id: string; name: string } | null;
    tags: { id: string; name: string }[] | null;
    current_location: string | null;
};

type Props = {
    documents: { data: DocumentRow[] };
    filters: {
        q: string | null;
        document_type_id: string | null;
        tag_ids: string[];
        from: string | null;
        to: string | null;
    };
    documentTypes: { id: string; name: string }[];
    tags: { id: string; name: string }[];
};

export default function DocumentIndex({
    documents,
    filters,
    documentTypes,
}: Props) {
    const t = useTranslation();
    const { workspace } = usePage().props;
    const [layout, setLayout] = useState<'table' | 'cards'>('table');
    const [selected, setSelected] = useState<Set<string>>(new Set());

    setLayoutProps({
        breadcrumbs: [
            {
                title: t('documents.index.title'),
                href: workspace ? documentsIndex.url(workspace.id) : '#',
            },
        ],
    });

    if (!workspace) {
        return null;
    }

    const applyFilters = (next: Partial<Props['filters']>) => {
        router.get(
            documentsIndex.url(workspace.id),
            { ...filters, ...next },
            { preserveState: true, replace: true },
        );
    };

    const toggleSelected = (id: string, checked: boolean) => {
        const next = new Set(selected);

        if (checked) {
            next.add(id);
        } else {
            next.delete(id);
        }

        setSelected(next);
    };

    const allSelected =
        documents.data.length > 0 &&
        documents.data.every((doc) => selected.has(doc.id));

    const exportDocuments = () => {
        router.post(
            startExport.url(workspace.id),
            {},
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title={t('documents.index.title')} />

            <div className="space-y-6 p-6">
                <div className="flex items-end justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {t('documents.index.title')}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {documents.data.length === 1
                                ? t('documents.index.document_count_one', {
                                      count: documents.data.length,
                                  })
                                : t('documents.index.document_count_other', {
                                      count: documents.data.length,
                                  })}
                        </p>
                    </div>
                    <div className="flex items-center gap-3">
                        <div className="flex gap-1 rounded-md border bg-muted p-0.5">
                            <Button
                                type="button"
                                variant={
                                    layout === 'table' ? 'secondary' : 'ghost'
                                }
                                size="sm"
                                onClick={() => setLayout('table')}
                            >
                                {t('documents.index.view_table')}
                            </Button>
                            <Button
                                type="button"
                                variant={
                                    layout === 'cards' ? 'secondary' : 'ghost'
                                }
                                size="sm"
                                onClick={() => setLayout('cards')}
                            >
                                {t('documents.index.view_cards')}
                            </Button>
                        </div>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={exportDocuments}
                        >
                            {t('documents.index.export')}
                        </Button>
                        <Button
                            size="sm"
                            onClick={() =>
                                router.visit(documentCreate.url(workspace.id))
                            }
                        >
                            {t('documents.index.new_document')}
                        </Button>
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <Input
                        placeholder={t('documents.index.search_placeholder')}
                        defaultValue={filters.q ?? ''}
                        className="max-w-xs"
                        onChange={(event) =>
                            applyFilters({ q: event.target.value || null })
                        }
                    />
                    <Select
                        value={filters.document_type_id ?? ALL}
                        onValueChange={(value) =>
                            applyFilters({
                                document_type_id: value === ALL ? null : value,
                            })
                        }
                    >
                        <SelectTrigger className="w-44">
                            <SelectValue
                                placeholder={t(
                                    'documents.index.filter_type_placeholder',
                                )}
                            />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>
                                {t('documents.index.filter_all_types')}
                            </SelectItem>
                            {documentTypes.map((type) => (
                                <SelectItem key={type.id} value={type.id}>
                                    {type.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Input
                        type="date"
                        value={filters.from ?? ''}
                        className="w-40"
                        onChange={(event) =>
                            applyFilters({ from: event.target.value || null })
                        }
                    />
                    <Input
                        type="date"
                        value={filters.to ?? ''}
                        className="w-40"
                        onChange={(event) =>
                            applyFilters({ to: event.target.value || null })
                        }
                    />
                </div>

                {selected.size > 0 && (
                    <div className="flex items-center gap-3 rounded-lg border border-primary bg-secondary px-3.5 py-2.5">
                        <span className="text-sm font-medium">
                            {t('documents.index.selected_count', {
                                count: selected.size,
                            })}
                        </span>
                        <div className="flex-1" />
                        <Button size="sm" disabled>
                            {t('documents.index.bulk_move')}
                        </Button>
                        <Button variant="outline" size="sm" disabled>
                            {t('documents.index.add_tag')}
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setSelected(new Set())}
                        >
                            {t('documents.index.clear')}
                        </Button>
                    </div>
                )}

                {documents.data.length === 0 && (
                    <div className="rounded-xl border border-dashed p-12 text-center">
                        <div className="font-semibold">
                            {t('documents.index.empty_title')}
                        </div>
                        <div className="text-sm text-muted-foreground">
                            {t('documents.index.empty_description')}
                        </div>
                    </div>
                )}

                {documents.data.length > 0 && layout === 'table' && (
                    <div className="overflow-hidden rounded-xl border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-9">
                                        <Checkbox
                                            checked={allSelected}
                                            onCheckedChange={(checked) =>
                                                setSelected(
                                                    checked === true
                                                        ? new Set(
                                                              documents.data.map(
                                                                  (doc) =>
                                                                      doc.id,
                                                              ),
                                                          )
                                                        : new Set(),
                                                )
                                            }
                                        />
                                    </TableHead>
                                    <TableHead>
                                        {t('documents.index.column_document')}
                                    </TableHead>
                                    <TableHead>
                                        {t('documents.index.column_type')}
                                    </TableHead>
                                    <TableHead>
                                        {t('documents.index.column_date')}
                                    </TableHead>
                                    <TableHead>
                                        {t('documents.index.column_location')}
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {documents.data.map((doc) => (
                                    <TableRow
                                        key={doc.id}
                                        className="cursor-pointer"
                                        onClick={() =>
                                            router.visit(
                                                documentShow.url(doc.id),
                                            )
                                        }
                                    >
                                        <TableCell
                                            onClick={(event) =>
                                                event.stopPropagation()
                                            }
                                        >
                                            <Checkbox
                                                checked={selected.has(doc.id)}
                                                onCheckedChange={(checked) =>
                                                    toggleSelected(
                                                        doc.id,
                                                        checked === true,
                                                    )
                                                }
                                            />
                                        </TableCell>
                                        <TableCell className="font-medium">
                                            {doc.title}
                                        </TableCell>
                                        <TableCell>
                                            {doc.document_type && (
                                                <Badge variant="secondary">
                                                    {doc.document_type.name}
                                                </Badge>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {doc.document_date ?? '—'}
                                        </TableCell>
                                        <TableCell className="font-mono text-xs">
                                            {doc.current_location ?? '—'}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}

                {documents.data.length > 0 && layout === 'cards' && (
                    <div className="grid grid-cols-1 gap-3.5 sm:grid-cols-2 lg:grid-cols-3">
                        {documents.data.map((doc) => (
                            <button
                                key={doc.id}
                                type="button"
                                onClick={() =>
                                    router.visit(documentShow.url(doc.id))
                                }
                                className="flex flex-col gap-3 rounded-xl border bg-card p-4 text-left shadow-sm hover:bg-muted"
                            >
                                <div className="flex items-start justify-between gap-2">
                                    <span className="flex items-center gap-2 font-semibold">
                                        <FileTextIcon className="size-4 text-muted-foreground" />
                                        {doc.title}
                                    </span>
                                    {doc.document_type && (
                                        <Badge variant="secondary">
                                            {doc.document_type.name}
                                        </Badge>
                                    )}
                                </div>
                                <div className="flex items-center justify-between border-t pt-2.5 text-xs text-muted-foreground">
                                    <span className="font-mono">
                                        {doc.current_location ??
                                            t('documents.index.unfiled')}
                                    </span>
                                    <span>{doc.document_date ?? ''}</span>
                                </div>
                            </button>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}
