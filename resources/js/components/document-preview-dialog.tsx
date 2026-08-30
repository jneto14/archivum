import { ChevronLeftIcon, ChevronRightIcon } from 'lucide-react';
import * as pdfjsLib from 'pdfjs-dist';
import type { PDFDocumentProxy } from 'pdfjs-dist';
import pdfWorkerSrc from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useTranslation } from '@/hooks/use-translation';
import { preview as attachmentPreview } from '@/routes/attachments';

pdfjsLib.GlobalWorkerOptions.workerSrc = pdfWorkerSrc;

type PreviewableAttachment = {
    id: string;
    filename: string;
    mime_type: string;
};

type Props = {
    attachment: PreviewableAttachment | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

function PdfPreview({ url }: { url: string }) {
    const t = useTranslation();
    const canvasRef = useRef<HTMLCanvasElement>(null);
    const docRef = useRef<PDFDocumentProxy | null>(null);
    const [pageNumber, setPageNumber] = useState(1);
    const [numPages, setNumPages] = useState<number | null>(null);
    const [error, setError] = useState(false);

    useEffect(() => {
        let cancelled = false;
        const loadingTask = pdfjsLib.getDocument({ url });

        loadingTask.promise
            .then((doc) => {
                if (cancelled) {
                    return;
                }

                docRef.current = doc;
                setNumPages(doc.numPages);
                setPageNumber(1);
            })
            .catch(() => !cancelled && setError(true));

        return () => {
            cancelled = true;
            loadingTask.destroy();
        };
    }, [url]);

    useEffect(() => {
        const doc = docRef.current;
        const canvas = canvasRef.current;

        if (!doc || !canvas || numPages === null) {
            return;
        }

        let cancelled = false;

        doc.getPage(pageNumber).then((page) => {
            if (cancelled) {
                return;
            }

            const viewport = page.getViewport({ scale: 1.5 });
            canvas.width = viewport.width;
            canvas.height = viewport.height;

            const context = canvas.getContext('2d');

            if (!context) {
                return;
            }

            page.render({ canvasContext: context, viewport, canvas });
        });

        return () => {
            cancelled = true;
        };
    }, [pageNumber, numPages]);

    if (error) {
        return (
            <p className="text-sm text-muted-foreground">
                {t('documents.show.preview_failed')}
            </p>
        );
    }

    return (
        <div className="flex flex-col items-center gap-3">
            <div className="max-h-[70vh] overflow-auto rounded-md border bg-muted">
                <canvas ref={canvasRef} />
            </div>
            {numPages !== null && (
                <div className="flex items-center gap-3">
                    <Button
                        variant="outline"
                        size="icon"
                        disabled={pageNumber <= 1}
                        onClick={() =>
                            setPageNumber((page) => Math.max(1, page - 1))
                        }
                    >
                        <ChevronLeftIcon />
                    </Button>
                    <span className="text-sm text-muted-foreground">
                        {t('documents.show.preview_page_of', {
                            current: pageNumber,
                            total: numPages,
                        })}
                    </span>
                    <Button
                        variant="outline"
                        size="icon"
                        disabled={pageNumber >= numPages}
                        onClick={() =>
                            setPageNumber((page) =>
                                Math.min(numPages, page + 1),
                            )
                        }
                    >
                        <ChevronRightIcon />
                    </Button>
                </div>
            )}
        </div>
    );
}

export function DocumentPreviewDialog({
    attachment,
    open,
    onOpenChange,
}: Props) {
    const t = useTranslation();

    if (attachment === null) {
        return null;
    }

    const url = attachmentPreview.url(attachment.id);
    const isPdf = attachment.mime_type === 'application/pdf';
    const isImage = attachment.mime_type.startsWith('image/');

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle className="truncate">
                        {attachment.filename}
                    </DialogTitle>
                </DialogHeader>

                {isPdf && <PdfPreview url={url} />}

                {isImage && (
                    <img
                        src={url}
                        alt={attachment.filename}
                        className="max-h-[70vh] w-full rounded-md border object-contain"
                    />
                )}

                {!isPdf && !isImage && (
                    <p className="text-sm text-muted-foreground">
                        {t('documents.show.preview_not_supported')}
                    </p>
                )}
            </DialogContent>
        </Dialog>
    );
}
