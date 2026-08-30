import {
    ChevronLeftIcon,
    ChevronRightIcon,
    MaximizeIcon,
    ZoomInIcon,
    ZoomOutIcon,
} from 'lucide-react';
import * as pdfjsLib from 'pdfjs-dist';
import type { PDFDocumentProxy, RenderTask } from 'pdfjs-dist';
import pdfWorkerSrc from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useTranslation } from '@/hooks/use-translation';
import { preview as attachmentPreview } from '@/routes/attachments';

pdfjsLib.GlobalWorkerOptions.workerSrc = pdfWorkerSrc;

/** Zoom is a multiplier on top of fit-to-width, so 1 always means "fits". */
const MIN_ZOOM = 0.5;
const MAX_ZOOM = 4;
const ZOOM_STEP = 0.25;

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
    const containerRef = useRef<HTMLDivElement>(null);
    const canvasRef = useRef<HTMLCanvasElement>(null);
    const docRef = useRef<PDFDocumentProxy | null>(null);
    const renderTaskRef = useRef<RenderTask | null>(null);
    const [pageNumber, setPageNumber] = useState(1);
    const [numPages, setNumPages] = useState<number | null>(null);
    const [zoom, setZoom] = useState(1);
    const [containerWidth, setContainerWidth] = useState(0);
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
                setZoom(1);
            })
            .catch(() => !cancelled && setError(true));

        return () => {
            cancelled = true;
            loadingTask.destroy();
        };
    }, [url]);

    /**
     * Track the available width so the page can be scaled to fit it. Without
     * this the canvas renders at a fixed scale and simply overflows the dialog.
     */
    useEffect(() => {
        const container = containerRef.current;

        if (container === null) {
            return;
        }

        const observer = new ResizeObserver(([entry]) =>
            setContainerWidth(entry.contentRect.width),
        );

        observer.observe(container);

        return () => observer.disconnect();
    }, []);

    useEffect(() => {
        const doc = docRef.current;
        const canvas = canvasRef.current;

        if (!doc || !canvas || numPages === null || containerWidth === 0) {
            return;
        }

        let cancelled = false;

        doc.getPage(pageNumber).then((page) => {
            if (cancelled) {
                return;
            }

            const unscaled = page.getViewport({ scale: 1 });
            const viewport = page.getViewport({
                scale: (containerWidth / unscaled.width) * zoom,
            });

            const context = canvas.getContext('2d');

            if (!context) {
                return;
            }

            // Render at device resolution, then scale back down via CSS, so the
            // page isn't blurry on HiDPI displays.
            const ratio = window.devicePixelRatio || 1;
            canvas.width = Math.floor(viewport.width * ratio);
            canvas.height = Math.floor(viewport.height * ratio);
            canvas.style.width = `${viewport.width}px`;
            canvas.style.height = `${viewport.height}px`;

            // A canvas can only host one render at a time; zooming or resizing
            // mid-render throws without this.
            renderTaskRef.current?.cancel();

            const task = page.render({
                canvasContext: context,
                viewport,
                canvas,
                transform: ratio === 1 ? undefined : [ratio, 0, 0, ratio, 0, 0],
            });

            renderTaskRef.current = task;

            task.promise.catch(() => {
                // Cancellation is expected whenever the page, zoom or width changes.
            });
        });

        return () => {
            cancelled = true;
        };
    }, [pageNumber, numPages, zoom, containerWidth]);

    const changeZoom = useCallback((delta: number) => {
        setZoom((current) =>
            Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, current + delta)),
        );
    }, []);

    if (error) {
        return (
            <p className="text-sm text-muted-foreground">
                {t('documents.show.preview_failed')}
            </p>
        );
    }

    return (
        <div className="flex w-full min-w-0 flex-col gap-3">
            <div
                ref={containerRef}
                className="max-h-[70vh] w-full min-w-0 overflow-auto rounded-md border bg-muted"
            >
                <canvas ref={canvasRef} className="block" />
            </div>

            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-1">
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <Button
                                variant="outline"
                                size="icon"
                                disabled={zoom <= MIN_ZOOM}
                                onClick={() => changeZoom(-ZOOM_STEP)}
                            >
                                <ZoomOutIcon />
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent>
                            {t('documents.show.preview_zoom_out')}
                        </TooltipContent>
                    </Tooltip>
                    <span className="w-14 text-center text-sm text-muted-foreground tabular-nums">
                        {Math.round(zoom * 100)}%
                    </span>
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <Button
                                variant="outline"
                                size="icon"
                                disabled={zoom >= MAX_ZOOM}
                                onClick={() => changeZoom(ZOOM_STEP)}
                            >
                                <ZoomInIcon />
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent>
                            {t('documents.show.preview_zoom_in')}
                        </TooltipContent>
                    </Tooltip>
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <Button
                                variant="outline"
                                size="icon"
                                disabled={zoom === 1}
                                onClick={() => setZoom(1)}
                            >
                                <MaximizeIcon />
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent>
                            {t('documents.show.preview_fit_width')}
                        </TooltipContent>
                    </Tooltip>
                </div>

                {numPages !== null && numPages > 1 && (
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
            <DialogContent className="min-w-0 sm:max-w-4xl">
                <DialogHeader>
                    {/* pr-8 clears the absolutely positioned close button. */}
                    <DialogTitle className="truncate pr-8">
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
