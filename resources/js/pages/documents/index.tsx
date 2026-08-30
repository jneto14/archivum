import { Head, router, setLayoutProps, usePage } from '@inertiajs/react';
import { FileTextIcon, LayoutGridIcon, TableIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { PageContainer } from '@/components/page-container';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { Panel } from '@/components/panel';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useDateFormatter } from '@/hooks/use-date-formatter';
import { useTranslation } from '@/hooks/use-translation';
import {
    create as documentCreate,
    index as documentsIndex,
    show as documentShow,
} from '@/routes/documents';
import { store as startExport } from '@/routes/workspaces/tasks';

const ALL = '__all__';
const LAYOUT_STORAGE_KEY = 'archivum.documents.layout';

type Layout = 'table' | 'cards';

type DocumentRow = {
    id: string;
    title: string;
    document_date: string | null;
    document_type: { id: string; name: string } | null;
    tags: { id: string; name: string }[] | null;
    current_location: string | null;
};

type Props = {
    documents: {
        data: DocumentRow[];
        links: { prev: string | null; next: string | null };
        meta: { from: number | null; to: number | null; total: number };
    };
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

/**
 * Read the persisted layout choice. Wrapped because storage access throws
 * outright in some privacy modes.
 */
function readStoredLayout(): Layout {
    try {
        return window.localStorage.getItem(LAYOUT_STORAGE_KEY) === 'cards'
            ? 'cards'
            : 'table';
    } catch {
        return 'table';
    }
}

export default function DocumentIndex({
    documents,
    filters,
    documentTypes,
    tags,
}: Props) {
    const t = useTranslation();
    const { formatDate } = useDateFormatter();
    const { workspace } = usePage().props;
    const [layout, setLayout] = useState<Layout>(readStoredLayout);

    setLayoutProps({
        breadcrumbs: [
            {
                title: t('documents.index.title'),
                href: workspace ? documentsIndex.url(workspace.id) : '#',
            },
        ],
    });

    useEffect(() => {
        try {
            window.localStorage.setItem(LAYOUT_STORAGE_KEY, layout);
        } catch {
            // Persisting the preference is a convenience; ignore storage failures.
        }
    }, [layout]);

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

    const exportDocuments = () => {
        router.post(
            startExport.url(workspace.id),
            {},
            { preserveScroll: true },
        );
    };

    const total = documents.meta.total;
    const selectedTagId = filters.tag_ids[0] ?? ALL;

    return (
        <>
            <Head title={t('documents.index.title')} />

            <PageContainer width="wide">
                <PageHeader
                    title={t('documents.index.title')}
                    description={
                        total === 1
                            ? t('documents.index.document_count_one', {
                                  count: total,
                              })
                            : t('documents.index.document_count_other', {
                                  count: total,
                              })
                    }
                >
                    <ToggleGroup
                        type="single"
                        variant="outline"
                        size="sm"
                        value={layout}
                        onValueChange={(value) =>
                            value !== '' && setLayout(value as Layout)
                        }
                    >
                        <ToggleGroupItem
                            value="table"
                            aria-label={t('documents.index.view_table')}
                        >
                            <TableIcon />
                            {t('documents.index.view_table')}
                        </ToggleGroupItem>
                        <ToggleGroupItem
                            value="cards"
                            aria-label={t('documents.index.view_cards')}
                        >
                            <LayoutGridIcon />
                            {t('documents.index.view_cards')}
                        </ToggleGroupItem>
                    </ToggleGroup>
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
                </PageHeader>

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
                    <Select
                        value={selectedTagId}
                        onValueChange={(value) =>
                            applyFilters({
                                tag_ids: value === ALL ? [] : [value],
                            })
                        }
                    >
                        <SelectTrigger className="w-44">
                            <SelectValue
                                placeholder={t(
                                    'documents.index.filter_tag_placeholder',
                                )}
                            />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>
                                {t('documents.index.filter_all_tags')}
                            </SelectItem>
                            {tags.map((tag) => (
                                <SelectItem key={tag.id} value={tag.id}>
                                    {tag.name}
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

                {documents.data.length === 0 && (
                    <EmptyState
                        title={t('documents.index.empty_title')}
                        description={t('documents.index.empty_description')}
                    />
                )}

                {documents.data.length > 0 && layout === 'table' && (
                    <Panel>
                        <Table>
                            <TableHeader>
                                <TableRow>
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
                                            {doc.document_date
                                                ? formatDate(doc.document_date)
                                                : '—'}
                                        </TableCell>
                                        <TableCell className="font-mono text-xs">
                                            {doc.current_location ?? '—'}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </Panel>
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
                                className="flex flex-col gap-3 rounded-xl border bg-card p-4 text-left shadow-sm hover:bg-accent"
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
                                    <span>
                                        {doc.document_date
                                            ? formatDate(doc.document_date)
                                            : ''}
                                    </span>
                                </div>
                            </button>
                        ))}
                    </div>
                )}

                {documents.data.length > 0 && (
                    <Pagination
                        prev={documents.links.prev}
                        next={documents.links.next}
                        from={documents.meta.from}
                        to={documents.meta.to}
                        total={total}
                    />
                )}
            </PageContainer>
        </>
    );
}
