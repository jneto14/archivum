import { Head, router, setLayoutProps, usePage } from '@inertiajs/react';
import { DownloadIcon, EyeIcon, Trash2Icon, XIcon } from 'lucide-react';
import { useRef, useState } from 'react';
import type { ChangeEvent } from 'react';
import AttachmentController from '@/actions/App/Http/Controllers/Documents/AttachmentController';
import { DocumentCameraDialog } from '@/components/document-camera-dialog';
import { DocumentCaptureDialog } from '@/components/document-capture-dialog';
import { DocumentPreviewDialog } from '@/components/document-preview-dialog';
import InputError from '@/components/input-error';
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
import { Input } from '@/components/ui/input';
import { Progress } from '@/components/ui/progress';
import { Skeleton } from '@/components/ui/skeleton';
import { useCameraAccess } from '@/hooks/use-camera-access';
import { useDateFormatter } from '@/hooks/use-date-formatter';
import { useTranslation } from '@/hooks/use-translation';
import { formatBytes, randomId } from '@/lib/utils';
import {
    destroy as attachmentDestroy,
    show as attachmentShow,
    store as attachmentStore,
} from '@/routes/attachments';
import {
    edit as documentEdit,
    index as documentsIndex,
    move as moveStore,
    show as documentShow,
} from '@/routes/documents';

type LocationSuggestion = {
    /**
     * `id` is null when the recommendation is a location that does not exist
     * yet: suggesting one must not create it, so picking it is what does (the
     * move is posted with the scheme instead of a node, and the server resolves
     * the same location again, this time for real).
     */
    node: { id: string | null; value: string; path: string };
    documentsCount: number;
    capacity: number | null;
    recommended: boolean;
};

type Location = {
    id: string;
    value: string;
    path: string;
    documentsCount: number;
    capacity: number | null;
};

type OcrStatus =
    | 'pending'
    | 'processing'
    | 'completed'
    | 'skipped'
    | 'unavailable'
    | 'failed';

type AttachmentRow = {
    id: string;
    filename: string;
    mime_type: string;
    /** Appended by the model — see DocumentAttachment::INLINE_SAFE_TYPES. */
    is_previewable: boolean;
    size: number;
    ocr_status: OcrStatus;
    created_at: string;
    uploader: { id: string; name: string } | null;
    /** An earlier attachment with near-identical text, until somebody dismisses the warning. */
    duplicate_of: {
        document_id: string;
        document_title: string | null;
        filename: string;
    } | null;
};

/**
 * Translation key for an attachment's text-extraction state, or null when
 * there is nothing worth saying.
 *
 * `completed` is deliberately silent: it is the ordinary outcome, and
 * labelling every readable file would bury the two states a user can act on —
 * a failure worth retrying, and an installation missing the OCR binaries.
 */
const ocrStatusKeys = {
    pending: 'documents.show.ocr_pending',
    processing: 'documents.show.ocr_processing',
    skipped: 'documents.show.ocr_skipped',
    unavailable: 'documents.show.ocr_unavailable',
    failed: 'documents.show.ocr_failed',
    completed: null,
} as const;

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
    /** The workspace's scheme, used to file into a suggested location that does not exist yet. */
    schemeId: string | null;
    locationSuggestions: LocationSuggestion[];
    /** Every location in the scheme, loaded on demand when the picker is opened. */
    locations?: Location[];
    /** How many values the extracted text has to offer for fields still empty. The values themselves live on the edit form, which is where they can be accepted. */
    metadata_suggestions_count: number;
    active_capture_session: { id: string; photos_count: number } | null;
};

export default function DocumentShow({
    document,
    canFile,
    schemeId,
    locationSuggestions,
    locations,
    metadata_suggestions_count: metadataSuggestionsCount,
    active_capture_session: activeCaptureSession,
}: Props) {
    const t = useTranslation();
    const { formatDate, formatDateTime } = useDateFormatter();
    const fileInputRef = useRef<HTMLInputElement>(null);
    const { workspace } = usePage().props;
    const [moveOpen, setMoveOpen] = useState(false);
    // The picker starts on the suggestions and only reveals the full list when
    // asked, which is also what triggers loading it.
    const [browsingLocations, setBrowsingLocations] = useState(false);
    const [locationQuery, setLocationQuery] = useState('');
    // Always starts closed, even if the page loads with a session already
    // active (a reload mid-pairing, or one left open from an earlier visit)
    // — popping the dialog open on its own, unasked, was more surprising
    // than useful. The button still finds that same session rather than
    // starting a redundant one, since DocumentCaptureDialog only creates a
    // new session when it opens with none active.
    const [captureOpen, setCaptureOpen] = useState(false);
    const [cameraOpen, setCameraOpen] = useState(false);
    // Decides which scan this button offers: the camera in your hand, or a QR
    // code to bring another device to it. When it is the QR because there was
    // no camera to open, that dialog says so rather than leaving the user to
    // wonder why the scanner never appeared.
    const cameraAccess = useCameraAccess();
    const cameraAvailable = cameraAccess === 'available';
    // Each queued file carries an id rather than being identified by its
    // position. Rows are removed one at a time, and keying them by index made
    // React reuse a removed row's DOM for the row that moved up into its
    // place. A name is not enough on its own — picking the same file twice is
    // legitimate, and two identical keys is its own bug.
    const [queue, setQueue] = useState<{ id: string; file: File }[]>([]);
    const [uploadError, setUploadError] = useState<string | undefined>(
        undefined,
    );
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

    /**
     * Add the picked files to the queue rather than replacing it, so several
     * folders can be gathered before uploading. The input's value is cleared
     * afterwards, otherwise picking the same file again fires no change event.
     */
    const queueFiles = (event: ChangeEvent<HTMLInputElement>) => {
        const picked = Array.from(event.target.files ?? []);

        if (picked.length > 0) {
            setQueue((current) => [
                ...current,
                ...picked.map((file) => ({ id: randomId(), file })),
            ]);
            setUploadError(undefined);
        }

        event.target.value = '';
    };

    const removeQueued = (id: string) => {
        setQueue((current) => current.filter((queued) => queued.id !== id));
    };

    const uploadAttachments = () => {
        if (queue.length === 0) {
            return;
        }

        router.post(
            attachmentStore.url(document.id),
            { files: queue.map((queued) => queued.file) },
            {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => {
                    setQueue([]);
                    setUploadError(undefined);
                },
                // The whole batch is rejected or none of it is, so a single
                // message is the whole story. Without this the upload used to
                // fail in complete silence.
                onError: (errors) =>
                    setUploadError(
                        errors.files ??
                            Object.values(errors).find(
                                (message) => message !== undefined,
                            ),
                    ),
            },
        );
    };

    const fileDocument = (
        payload: { node_id: string } | { scheme_id: string },
    ) => {
        router.post(moveStore.url(document.id), payload, {
            preserveScroll: true,
            onSuccess: () => setMoveOpen(false),
        });
    };

    /**
     * File into a suggestion: an existing location by id, or — when the
     * recommendation is one that has yet to be created — by scheme, letting the
     * server open it.
     */
    const pickSuggestion = (suggestion: LocationSuggestion) => {
        if (suggestion.node.id !== null) {
            fileDocument({ node_id: suggestion.node.id });

            return;
        }

        if (schemeId !== null) {
            fileDocument({ scheme_id: schemeId });
        }
    };

    const openLocationBrowser = () => {
        setBrowsingLocations(true);

        if (locations === undefined) {
            router.reload({ only: ['locations'] });
        }
    };

    const matchingLocations = (locations ?? []).filter((location) =>
        location.path
            .toLowerCase()
            .includes(locationQuery.trim().toLowerCase()),
    );

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
                                {metadataSuggestionsCount > 0 && (
                                    <div className="mt-4 flex flex-wrap items-center gap-2 rounded-md border border-dashed p-3">
                                        <p className="min-w-0 flex-1 text-sm text-muted-foreground">
                                            {t(
                                                metadataSuggestionsCount === 1
                                                    ? 'documents.show.metadata_suggestions_one'
                                                    : 'documents.show.metadata_suggestions_other',
                                                {
                                                    count: metadataSuggestionsCount,
                                                },
                                            )}
                                        </p>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="shrink-0"
                                            onClick={() =>
                                                router.visit(
                                                    documentEdit.url(
                                                        document.id,
                                                    ),
                                                )
                                            }
                                        >
                                            {t(
                                                'documents.show.metadata_suggestions_review',
                                            )}
                                        </Button>
                                    </div>
                                )}
                                <div className="mt-4 flex flex-wrap gap-1.5 border-t pt-4">
                                    {(document.tags ?? []).map((tag) => (
                                        <Badge key={tag.id} variant="outline">
                                            {tag.name}
                                        </Badge>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>

                        <Card className="@container/attachments">
                            <CardHeader className="flex-row flex-wrap items-center justify-between gap-2">
                                <CardTitle>
                                    {t('documents.show.attachments_title')}
                                </CardTitle>
                                <input
                                    ref={fileInputRef}
                                    type="file"
                                    multiple
                                    className="sr-only"
                                    onChange={queueFiles}
                                />
                                {/* Narrow, these stack full width under the
                                    title rather than wrapping into pills of
                                    different widths against the right edge. One
                                    per row, not two: "Digitalizar com o
                                    telemóvel" does not fit half of a phone, and
                                    a button does not wrap its own label — it
                                    overflows its border instead.

                                    The card's own width decides, not the
                                    viewport's: the sidebar moves this by 208px
                                    without the viewport changing at all. */}
                                <div className="grid w-full grid-cols-1 gap-2 @lg/attachments:flex @lg/attachments:w-auto @lg/attachments:min-w-0 @lg/attachments:flex-1 @lg/attachments:flex-wrap @lg/attachments:items-center @lg/attachments:justify-end">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="@lg/attachments:shrink-0"
                                        onClick={() =>
                                            cameraAvailable
                                                ? setCameraOpen(true)
                                                : setCaptureOpen(true)
                                        }
                                    >
                                        {cameraAvailable
                                            ? t('documents.show.scan_button')
                                            : t(
                                                  'documents.show.scan_with_phone_button',
                                              )}
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="@lg/attachments:shrink-0"
                                        onClick={() =>
                                            fileInputRef.current?.click()
                                        }
                                    >
                                        {t(
                                            'documents.show.choose_files_button',
                                        )}
                                    </Button>
                                    <Button
                                        size="sm"
                                        className="@lg/attachments:shrink-0"
                                        onClick={uploadAttachments}
                                        disabled={queue.length === 0}
                                    >
                                        {queue.length === 0
                                            ? t('documents.show.upload_button')
                                            : t(
                                                  'documents.show.upload_button_count',
                                                  { count: queue.length },
                                              )}
                                    </Button>
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-2">
                                {queue.length > 0 && (
                                    <div className="space-y-2 rounded-md border border-dashed p-3">
                                        <p className="text-xs font-medium text-muted-foreground">
                                            {t(
                                                'documents.show.queued_files_title',
                                                { count: queue.length },
                                            )}
                                        </p>
                                        {queue.map((queued) => (
                                            <div
                                                key={queued.id}
                                                className="flex items-center gap-2"
                                            >
                                                <span className="min-w-0 flex-1 truncate text-sm">
                                                    {queued.file.name}
                                                </span>
                                                <span className="shrink-0 text-xs text-muted-foreground">
                                                    {formatBytes(
                                                        queued.file.size,
                                                    )}
                                                </span>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-7 shrink-0"
                                                    aria-label={t(
                                                        'documents.show.remove_queued_file',
                                                        {
                                                            name: queued.file
                                                                .name,
                                                        },
                                                    )}
                                                    onClick={() =>
                                                        removeQueued(queued.id)
                                                    }
                                                >
                                                    <XIcon className="size-4" />
                                                </Button>
                                            </div>
                                        ))}
                                    </div>
                                )}

                                <InputError message={uploadError} />

                                {(document.attachments ?? []).length === 0 && (
                                    <p className="text-sm text-muted-foreground">
                                        {t('documents.show.no_attachments')}
                                    </p>
                                )}
                                {(document.attachments ?? []).map(
                                    (attachment) => {
                                        const ocrKey =
                                            ocrStatusKeys[
                                                attachment.ocr_status
                                            ];
                                        const duplicate =
                                            attachment.duplicate_of;

                                        return (
                                            <div
                                                key={attachment.id}
                                                className="space-y-2 rounded-md border p-2"
                                            >
                                                <div className="flex items-center gap-3">
                                                    <div className="min-w-0 flex-1">
                                                        <div className="truncate text-sm font-medium">
                                                            {
                                                                attachment.filename
                                                            }
                                                        </div>
                                                        <div className="text-xs text-muted-foreground">
                                                            {formatBytes(
                                                                attachment.size,
                                                            )}
                                                            {ocrKey && (
                                                                <>
                                                                    {' · '}
                                                                    <span
                                                                        className={
                                                                            attachment.ocr_status ===
                                                                            'failed'
                                                                                ? 'text-destructive'
                                                                                : undefined
                                                                        }
                                                                    >
                                                                        {t(
                                                                            ocrKey,
                                                                        )}
                                                                    </span>
                                                                </>
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
                                                {duplicate && (
                                                    <div className="flex flex-wrap items-center gap-2 rounded-md bg-muted p-2">
                                                        <p className="min-w-0 flex-1 text-xs text-muted-foreground">
                                                            {t(
                                                                'documents.show.duplicate_warning',
                                                            )}{' '}
                                                            <span className="font-medium text-foreground">
                                                                {duplicate.document_title ??
                                                                    duplicate.filename}
                                                            </span>
                                                        </p>
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            className="shrink-0"
                                                            onClick={() =>
                                                                router.visit(
                                                                    documentShow.url(
                                                                        duplicate.document_id,
                                                                    ),
                                                                )
                                                            }
                                                        >
                                                            {t(
                                                                'documents.show.duplicate_open',
                                                            )}
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            className="shrink-0"
                                                            onClick={() =>
                                                                router.delete(
                                                                    AttachmentController.dismissDuplicate.url(
                                                                        attachment.id,
                                                                    ),
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            {t(
                                                                'documents.show.duplicate_dismiss',
                                                            )}
                                                        </Button>
                                                    </div>
                                                )}
                                            </div>
                                        );
                                    },
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

            <Dialog
                open={moveOpen}
                onOpenChange={(open) => {
                    setMoveOpen(open);

                    if (!open) {
                        setBrowsingLocations(false);
                        setLocationQuery('');
                    }
                }}
            >
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
                        {locationSuggestions.map((suggestion) => (
                            <LocationRow
                                key={suggestion.node.id ?? suggestion.node.path}
                                path={suggestion.node.path}
                                documentsCount={suggestion.documentsCount}
                                capacity={suggestion.capacity}
                                recommended={suggestion.recommended}
                                isNew={suggestion.node.id === null}
                                onSelect={() => pickSuggestion(suggestion)}
                            />
                        ))}
                    </div>
                    {browsingLocations ? (
                        <div className="space-y-2">
                            <Input
                                value={locationQuery}
                                onChange={(event) =>
                                    setLocationQuery(event.target.value)
                                }
                                placeholder={t(
                                    'documents.show.search_locations_placeholder',
                                )}
                                autoFocus
                            />
                            {locations === undefined ? (
                                <div className="space-y-2">
                                    <Skeleton className="h-16 w-full" />
                                    <Skeleton className="h-16 w-full" />
                                    <Skeleton className="h-16 w-full" />
                                </div>
                            ) : matchingLocations.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    {t('documents.show.no_matching_locations')}
                                </p>
                            ) : (
                                <div className="max-h-64 space-y-2 overflow-y-auto">
                                    {matchingLocations.map((location) => (
                                        <LocationRow
                                            key={location.id}
                                            path={location.path}
                                            documentsCount={
                                                location.documentsCount
                                            }
                                            capacity={location.capacity}
                                            isCurrent={
                                                location.path ===
                                                document.current_location
                                            }
                                            isFull={
                                                location.capacity !== null &&
                                                location.documentsCount >=
                                                    location.capacity
                                            }
                                            onSelect={() =>
                                                fileDocument({
                                                    node_id: location.id,
                                                })
                                            }
                                        />
                                    ))}
                                </div>
                            )}
                        </div>
                    ) : (
                        <Button
                            variant="outline"
                            onClick={openLocationBrowser}
                            disabled={!canFile}
                        >
                            {t('documents.show.browse_locations_button')}
                        </Button>
                    )}
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

            <DocumentCaptureDialog
                documentId={document.id}
                activeSession={activeCaptureSession}
                cameraAccess={cameraAccess}
                open={captureOpen}
                onOpenChange={setCaptureOpen}
            />

            {/* A scan taken here lands in the same queue a chosen file does, so
                a page shot with the camera and a PDF picked from disk go up
                together in one upload. */}
            <DocumentCameraDialog
                open={cameraOpen}
                onOpenChange={setCameraOpen}
                onCaptured={(file) => {
                    setQueue((current) => [
                        ...current,
                        { id: randomId(), file },
                    ]);
                    setUploadError(undefined);
                }}
                onUseAnotherDevice={() => setCaptureOpen(true)}
            />
        </>
    );
}

/**
 * One pickable location, used both for the suggestions and for the full list.
 *
 * A path is user-controlled text sitting next to a fill gauge, so it is the
 * part that gives: it truncates, the gauge does not.
 */
function LocationRow({
    path,
    documentsCount,
    capacity,
    recommended = false,
    isNew = false,
    isCurrent = false,
    isFull = false,
    onSelect,
}: {
    path: string;
    documentsCount: number;
    capacity: number | null;
    recommended?: boolean;
    /** The location does not exist yet and will be created by filing into it. */
    isNew?: boolean;
    /** Where the document already is: shown for orientation, but filing into it would do nothing. */
    isCurrent?: boolean;
    /** Holding as many documents as it has room for; MoveDocument refuses it. */
    isFull?: boolean;
    onSelect: () => void;
}) {
    const t = useTranslation();
    const pct =
        capacity !== null
            ? Math.round((documentsCount / capacity) * 100)
            : null;

    return (
        <button
            type="button"
            onClick={onSelect}
            disabled={isCurrent || isFull}
            className="flex w-full items-center gap-3 rounded-md border p-3 text-left hover:bg-accent disabled:cursor-default disabled:opacity-60 disabled:hover:bg-transparent"
        >
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="min-w-0 truncate font-mono text-sm font-semibold">
                        {path}
                    </span>
                    {recommended && (
                        <Badge className="shrink-0">
                            {t('documents.show.suggested_badge')}
                        </Badge>
                    )}
                    {isNew && (
                        <Badge variant="secondary" className="shrink-0">
                            {t('documents.show.new_location_badge')}
                        </Badge>
                    )}
                    {isCurrent && (
                        <Badge variant="outline" className="shrink-0">
                            {t('documents.show.current_location_badge')}
                        </Badge>
                    )}
                    {isFull && !isCurrent && (
                        <Badge variant="outline" className="shrink-0">
                            {t('documents.show.full_location_badge')}
                        </Badge>
                    )}
                </div>
                <div className="text-xs text-muted-foreground">
                    {documentsCount === 1
                        ? t('documents.show.location_suggestion_count_one', {
                              count: documentsCount,
                          })
                        : t('documents.show.location_suggestion_count_other', {
                              count: documentsCount,
                          })}
                    {capacity !== null ? ` / ${capacity}` : ''}
                </div>
            </div>
            {pct !== null && (
                <div className="w-24 shrink-0">
                    <Progress value={pct} />
                </div>
            )}
        </button>
    );
}
