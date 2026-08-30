import { Head, router, setLayoutProps, usePage } from '@inertiajs/react';
import { DownloadIcon, EyeIcon, Trash2Icon } from 'lucide-react';
import { useRef, useState } from 'react';
import { DocumentPreviewDialog } from '@/components/document-preview-dialog';
import { PageContainer } from '@/components/page-container';
import { PageHeader } from '@/components/page-header';
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
import { Progress } from '@/components/ui/progress';
import { useDateFormatter } from '@/hooks/use-date-formatter';
import { useTranslation } from '@/hooks/use-translation';
import { formatBytes } from '@/lib/utils';
import {
    destroy as attachmentDestroy,
    show as attachmentShow,
    store as attachmentStore,
} from '@/routes/attachments';
import {
    edit as documentEdit,
    index as documentsIndex,
    move as moveStore,
} from '@/routes/documents';

type LocationSuggestion = {
    node: { id: string; value: string; path: string };
    documentsCount: number;
    capacity: number | null;
    recommended: boolean;
};

type AttachmentRow = {
    id: string;
    filename: string;
    mime_type: string;
    size: number;
    created_at: string;
    uploader: { id: string; name: string } | null;
};

type Props = {
    document: {
        id: string;
        title: string;
        document_date: string | null;
        metadata: Record<string, string> | null;
        document_type: { id: string; name: string; key: string } | null;
        tags: { id: string; name: string }[] | null;
        current_location: string | null;
        creator: { id: string; name: string } | null;
        attachments: AttachmentRow[] | null;
        location_history:
            { id: string; path: string | null; created_at: string }[] | null;
    };
    canFile: boolean;
    locationSuggestions: LocationSuggestion[];
};

export default function DocumentShow({
    document,
    canFile,
    locationSuggestions,
}: Props) {
    const t = useTranslation();
    const { formatDate, formatDateTime } = useDateFormatter();
    const fileInputRef = useRef<HTMLInputElement>(null);
    const { workspace } = usePage().props;
    const [moveOpen, setMoveOpen] = useState(false);
    const [file, setFile] = useState<File | null>(null);
    const [previewAttachment, setPreviewAttachment] =
        useState<AttachmentRow | null>(null);

    setLayoutProps({
        breadcrumbs: [
            {
                title: t('documents.show.breadcrumb_documents'),
                href: workspace ? documentsIndex.url(workspace.id) : '#',
            },
            { title: document.title, href: '#' },
        ],
    });

    const uploadAttachment = () => {
        if (file === null) {
            return;
        }

        router.post(
            attachmentStore.url(document.id),
            { file },
            {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => setFile(null),
            },
        );
    };

    const pickLocation = (nodeId: string) => {
        router.post(
            moveStore.url(document.id),
            { node_id: nodeId },
            { preserveScroll: true, onSuccess: () => setMoveOpen(false) },
        );
    };

    return (
        <>
            <Head title={document.title} />

            <PageContainer>
                <PageHeader
                    title={document.title}
                    description={
                        document.document_type ? (
                            <Badge variant="secondary">
                                {document.document_type.name}
                            </Badge>
                        ) : undefined
                    }
                >
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            router.visit(documentEdit.url(document.id))
                        }
                    >
                        {t('documents.show.edit_button')}
                    </Button>
                    {canFile ? (
                        <Button size="sm" onClick={() => setMoveOpen(true)}>
                            {document.current_location
                                ? t('documents.show.move_document_button')
                                : t('documents.show.assign_location_button')}
                        </Button>
                    ) : (
                        <span className="text-xs text-muted-foreground">
                            {t('documents.show.filing_admin_only')}
                        </span>
                    )}
                </PageHeader>

                <div className="grid grid-cols-1 gap-4 lg:grid-cols-[1.6fr_1fr]">
                    <div className="space-y-4">
                        <Card>
                            <CardHeader>
                                <CardTitle>
                                    {t('documents.show.metadata_title')}
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    {document.document_date && (
                                        <div>
                                            <div className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                                {t(
                                                    'documents.show.document_date_label',
                                                )}
                                            </div>
                                            <div className="text-sm">
                                                {formatDate(
                                                    document.document_date,
                                                )}
                                            </div>
                                        </div>
                                    )}
                                    {Object.entries(
                                        document.metadata ?? {},
                                    ).map(([key, value]) => (
                                        <div key={key}>
                                            <div className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                                {key}
                                            </div>
                                            <div className="text-sm">
                                                {value}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                                <div className="mt-4 flex flex-wrap gap-1.5 border-t pt-4">
                                    {(document.tags ?? []).map((tag) => (
                                        <Badge key={tag.id} variant="outline">
                                            {tag.name}
                                        </Badge>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="flex-row items-center justify-between">
                                <CardTitle>
                                    {t('documents.show.attachments_title')}
                                </CardTitle>
                                <div className="flex items-center gap-2">
                                    <input
                                        ref={fileInputRef}
                                        type="file"
                                        className="sr-only"
                                        onChange={(event) =>
                                            setFile(
                                                event.target.files?.[0] ?? null,
                                            )
                                        }
                                    />
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            fileInputRef.current?.click()
                                        }
                                    >
                                        {t('documents.show.choose_file_button')}
                                    </Button>
                                    {file && (
                                        <span className="max-w-40 truncate text-xs text-muted-foreground">
                                            {file.name}
                                        </span>
                                    )}
                                    <Button
                                        size="sm"
                                        onClick={uploadAttachment}
                                        disabled={!file}
                                    >
                                        {t('documents.show.upload_button')}
                                    </Button>
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-2">
                                {(document.attachments ?? []).length === 0 && (
                                    <p className="text-sm text-muted-foreground">
                                        {t('documents.show.no_attachments')}
                                    </p>
                                )}
                                {(document.attachments ?? []).map(
                                    (attachment) => (
                                        <div
                                            key={attachment.id}
                                            className="flex items-center gap-3 rounded-md border p-2"
                                        >
                                            <div className="min-w-0 flex-1">
                                                <div className="truncate text-sm font-medium">
                                                    {attachment.filename}
                                                </div>
                                                <div className="text-xs text-muted-foreground">
                                                    {formatBytes(
                                                        attachment.size,
                                                    )}
                                                </div>
                                            </div>
                                            {(attachment.mime_type ===
                                                'application/pdf' ||
                                                attachment.mime_type.startsWith(
                                                    'image/',
                                                )) && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    title={t(
                                                        'documents.show.preview_button',
                                                    )}
                                                    onClick={() =>
                                                        setPreviewAttachment(
                                                            attachment,
                                                        )
                                                    }
                                                >
                                                    <EyeIcon />
                                                </Button>
                                            )}
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                asChild
                                            >
                                                <a
                                                    href={attachmentShow.url(
                                                        attachment.id,
                                                    )}
                                                >
                                                    <DownloadIcon />
                                                </a>
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    router.delete(
                                                        attachmentDestroy.url(
                                                            attachment.id,
                                                        ),
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                <Trash2Icon />
                                            </Button>
                                        </div>
                                    ),
                                )}
                            </CardContent>
                        </Card>
                    </div>

                    <div className="space-y-4">
                        <Card>
                            <CardHeader>
                                <CardTitle>
                                    {t(
                                        'documents.show.physical_location_title',
                                    )}
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                {document.current_location ? (
                                    <div className="rounded-md border bg-muted p-4 text-center font-mono text-lg font-semibold">
                                        {document.current_location}
                                    </div>
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        {t(
                                            'documents.show.location_not_assigned',
                                        )}
                                    </p>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>
                                    {t('documents.show.location_history_title')}
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                {(document.location_history ?? []).length ===
                                    0 && (
                                    <p className="text-sm text-muted-foreground">
                                        {t(
                                            'documents.show.no_location_history',
                                        )}
                                    </p>
                                )}
                                {(document.location_history ?? []).map(
                                    (entry) => (
                                        <div key={entry.id} className="text-sm">
                                            <div className="font-mono font-medium">
                                                {entry.path}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {entry.created_at
                                                    ? formatDateTime(
                                                          entry.created_at,
                                                      )
                                                    : '—'}
                                            </div>
                                        </div>
                                    ),
                                )}
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </PageContainer>

            <Dialog open={moveOpen} onOpenChange={setMoveOpen}>
                <DialogContent>
                    <DialogTitle>
                        {t('documents.show.assign_location_dialog_title')}
                    </DialogTitle>
                    <div className="space-y-2">
                        {locationSuggestions.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                {t('documents.show.no_location_suggestions')}
                            </p>
                        )}
                        {locationSuggestions.map((suggestion) => {
                            const pct =
                                suggestion.capacity !== null
                                    ? Math.round(
                                          (suggestion.documentsCount /
                                              suggestion.capacity) *
                                              100,
                                      )
                                    : null;

                            return (
                                <button
                                    key={suggestion.node.id}
                                    type="button"
                                    onClick={() =>
                                        pickLocation(suggestion.node.id)
                                    }
                                    className="flex w-full items-center gap-3 rounded-md border p-3 text-left hover:bg-accent"
                                >
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-2">
                                            <span className="font-mono text-sm font-semibold">
                                                {suggestion.node.path}
                                            </span>
                                            {suggestion.recommended && (
                                                <Badge>
                                                    {t(
                                                        'documents.show.suggested_badge',
                                                    )}
                                                </Badge>
                                            )}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {suggestion.documentsCount === 1
                                                ? t(
                                                      'documents.show.location_suggestion_count_one',
                                                      {
                                                          count: suggestion.documentsCount,
                                                      },
                                                  )
                                                : t(
                                                      'documents.show.location_suggestion_count_other',
                                                      {
                                                          count: suggestion.documentsCount,
                                                      },
                                                  )}
                                            {suggestion.capacity !== null
                                                ? ` / ${suggestion.capacity}`
                                                : ''}
                                        </div>
                                    </div>
                                    {pct !== null && (
                                        <div className="w-24">
                                            <Progress value={pct} />
                                        </div>
                                    )}
                                </button>
                            );
                        })}
                    </div>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button variant="ghost">
                                {t('documents.show.cancel_button')}
                            </Button>
                        </DialogClose>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <DocumentPreviewDialog
                attachment={previewAttachment}
                open={previewAttachment !== null}
                onOpenChange={(open) => !open && setPreviewAttachment(null)}
            />
        </>
    );
}
