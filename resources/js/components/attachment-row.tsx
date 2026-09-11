import {
    ChevronRightIcon,
    DownloadIcon,
    EllipsisVerticalIcon,
    EyeIcon,
    HistoryIcon,
    RefreshCwIcon,
    ReplaceIcon,
    SmartphoneIcon,
    Trash2Icon,
} from 'lucide-react';
import { useRef, useState } from 'react';
import type { ChangeEvent } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useDateFormatter } from '@/hooks/use-date-formatter';
import { useTranslation } from '@/hooks/use-translation';
import type { TranslationKey } from '@/lib/translations';
import { cn, formatBytes } from '@/lib/utils';
import { show as versionShow } from '@/routes/attachment-versions';
import { show as attachmentShow } from '@/routes/attachments';

export type AttachmentOcrStatus =
    | 'pending'
    | 'processing'
    | 'completed'
    | 'poorly_read'
    | 'skipped'
    | 'unavailable'
    | 'failed';

/** One file this attachment used to hold, newest replacement first. */
export type AttachmentVersion = {
    id: string;
    filename: string;
    size: number;
    uploaded_at: string;
    superseded_at: string;
    uploader: { id: string; name: string } | null;
};

export type Attachment = {
    id: string;
    filename: string;
    mime_type: string;
    /** Appended by the model — see DocumentAttachment::INLINE_SAFE_TYPES. */
    is_previewable: boolean;
    size: number;
    ocr_status: AttachmentOcrStatus;
    created_at: string;
    file_uploaded_at: string | null;
    uploader: { id: string; name: string } | null;
    /** An earlier attachment with near-identical text, until somebody dismisses the warning. */
    duplicate_of: {
        document_id: string;
        document_title: string | null;
        filename: string;
    } | null;
    versions: AttachmentVersion[];
};

/**
 * Translation key for an attachment's text-extraction state, or null when
 * there is nothing worth saying.
 *
 * `completed` is deliberately silent: it is the ordinary outcome, and
 * labelling every readable file would bury the two states a user can act on —
 * a failure worth retrying, and an installation missing the OCR binaries.
 */
const ocrStatusKeys: Record<AttachmentOcrStatus, TranslationKey | null> = {
    pending: 'documents.show.ocr_pending',
    processing: 'documents.show.ocr_processing',
    skipped: 'documents.show.ocr_skipped',
    unavailable: 'documents.show.ocr_unavailable',
    failed: 'documents.show.ocr_failed',
    poorly_read: 'documents.show.ocr_poorly_read',
    completed: null,
};

type Props = {
    attachment: Attachment;
    onPreview: (attachment: Attachment) => void;
    onReplace: (attachment: Attachment, file: File) => void;
    onReplaceWithPhone: (attachment: Attachment) => void;
    onReextract: (attachment: Attachment) => void;
    onDelete: (attachment: Attachment) => void;
    onRestoreVersion: (version: AttachmentVersion) => void;
    onDismissDuplicate: (attachment: Attachment) => void;
    onOpenDuplicate: (documentId: string) => void;
};

/**
 * One attachment on the document page: the file it holds now, the warnings
 * raised about it, and the files it used to hold.
 *
 * The actions moved behind a menu when replacing arrived (ARC-124). Preview
 * and download stay in the row because they are what people press; six icon
 * buttons beside a filename is the row that clips its last control the moment
 * a long name appears — see .ai/rules/js.md.
 */
export function AttachmentRow({
    attachment,
    onPreview,
    onReplace,
    onReplaceWithPhone,
    onReextract,
    onDelete,
    onRestoreVersion,
    onDismissDuplicate,
    onOpenDuplicate,
}: Props) {
    const t = useTranslation();
    const { formatDateTime } = useDateFormatter();
    const replaceInputRef = useRef<HTMLInputElement>(null);
    const [historyOpen, setHistoryOpen] = useState(false);

    const ocrKey = ocrStatusKeys[attachment.ocr_status];
    const duplicate = attachment.duplicate_of;
    const versions = attachment.versions ?? [];
    const beingRead =
        attachment.ocr_status === 'pending' ||
        attachment.ocr_status === 'processing';

    const pickReplacement = (event: ChangeEvent<HTMLInputElement>) => {
        const file = event.target.files?.[0];
        event.target.value = '';

        if (file) {
            onReplace(attachment, file);
        }
    };

    return (
        <div className="space-y-2 rounded-md border p-2">
            <input
                ref={replaceInputRef}
                type="file"
                className="sr-only"
                onChange={pickReplacement}
            />

            <div className="flex items-center gap-3">
                <div className="min-w-0 flex-1">
                    <div className="truncate text-sm font-medium">
                        {attachment.filename}
                    </div>
                    <div className="text-xs text-muted-foreground">
                        {formatBytes(attachment.size)}
                        {ocrKey && (
                            <>
                                {' · '}
                                <span
                                    className={
                                        attachment.ocr_status === 'failed'
                                            ? 'text-destructive'
                                            : undefined
                                    }
                                >
                                    {t(ocrKey)}
                                </span>
                            </>
                        )}
                    </div>
                </div>

                {(attachment.mime_type === 'application/pdf' ||
                    attachment.mime_type.startsWith('image/')) && (
                    <Button
                        variant="ghost"
                        size="sm"
                        className="shrink-0"
                        title={t('documents.show.preview_button')}
                        onClick={() => onPreview(attachment)}
                    >
                        <EyeIcon />
                    </Button>
                )}
                <Button variant="ghost" size="sm" className="shrink-0" asChild>
                    <a href={attachmentShow.url(attachment.id)}>
                        <DownloadIcon />
                    </a>
                </Button>

                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            variant="ghost"
                            size="sm"
                            className="shrink-0"
                            aria-label={t('documents.show.attachment_actions')}
                        >
                            <EllipsisVerticalIcon />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem
                            onSelect={() => replaceInputRef.current?.click()}
                        >
                            <ReplaceIcon />
                            {t('documents.show.replace_button')}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            onSelect={() => onReplaceWithPhone(attachment)}
                        >
                            <SmartphoneIcon />
                            {t('documents.show.replace_with_phone_button')}
                        </DropdownMenuItem>
                        {attachment.ocr_status !== 'unavailable' && (
                            <DropdownMenuItem
                                disabled={beingRead}
                                onSelect={() => onReextract(attachment)}
                            >
                                <RefreshCwIcon />
                                {t('documents.show.reextract_button')}
                            </DropdownMenuItem>
                        )}
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            variant="destructive"
                            onSelect={() => onDelete(attachment)}
                        >
                            <Trash2Icon />
                            {t('documents.show.attachment_delete_button')}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>

            {duplicate && (
                <div className="flex flex-wrap items-center gap-2 rounded-md bg-muted p-2">
                    <p className="min-w-0 flex-1 text-xs text-muted-foreground">
                        {t('documents.show.duplicate_warning')}{' '}
                        <span className="font-medium text-foreground">
                            {duplicate.document_title ?? duplicate.filename}
                        </span>
                    </p>
                    <Button
                        variant="outline"
                        size="sm"
                        className="shrink-0"
                        onClick={() => onOpenDuplicate(duplicate.document_id)}
                    >
                        {t('documents.show.duplicate_open')}
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        className="shrink-0"
                        onClick={() => onDismissDuplicate(attachment)}
                    >
                        {t('documents.show.duplicate_dismiss')}
                    </Button>
                </div>
            )}

            {versions.length > 0 && (
                <>
                    <button
                        type="button"
                        onClick={() => setHistoryOpen((open) => !open)}
                        aria-expanded={historyOpen}
                        className="flex items-center gap-1.5 text-xs text-muted-foreground hover:text-foreground"
                    >
                        <ChevronRightIcon
                            className={cn(
                                'size-3.5 shrink-0 transition-transform',
                                historyOpen && 'rotate-90',
                            )}
                        />
                        <HistoryIcon className="size-3.5 shrink-0" />
                        {versions.length === 1
                            ? t('documents.show.versions_toggle_one', {
                                  count: versions.length,
                              })
                            : t('documents.show.versions_toggle_other', {
                                  count: versions.length,
                              })}
                    </button>

                    {historyOpen && (
                        <div className="space-y-2 rounded-md bg-muted p-2">
                            {versions.map((version) => (
                                <div
                                    key={version.id}
                                    className="flex flex-wrap items-center gap-2"
                                >
                                    <div className="min-w-0 flex-1 basis-full @md/attachments:basis-0">
                                        <div className="truncate text-xs font-medium">
                                            {version.filename}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {formatBytes(version.size)}
                                            {' · '}
                                            {version.uploader
                                                ? t(
                                                      'documents.show.version_uploaded_by',
                                                      {
                                                          date: formatDateTime(
                                                              version.uploaded_at,
                                                          ),
                                                          name: version.uploader
                                                              .name,
                                                      },
                                                  )
                                                : t(
                                                      'documents.show.version_uploaded',
                                                      {
                                                          date: formatDateTime(
                                                              version.uploaded_at,
                                                          ),
                                                      },
                                                  )}
                                            {' · '}
                                            {t(
                                                'documents.show.version_replaced_at',
                                                {
                                                    date: formatDateTime(
                                                        version.superseded_at,
                                                    ),
                                                },
                                            )}
                                        </div>
                                    </div>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="shrink-0"
                                        asChild
                                    >
                                        <a href={versionShow.url(version.id)}>
                                            {t(
                                                'documents.show.version_download',
                                            )}
                                        </a>
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="shrink-0"
                                        onClick={() =>
                                            onRestoreVersion(version)
                                        }
                                    >
                                        {t('documents.show.version_restore')}
                                    </Button>
                                </div>
                            ))}
                        </div>
                    )}
                </>
            )}
        </div>
    );
}
