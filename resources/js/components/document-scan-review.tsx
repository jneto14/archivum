import { TriangleAlertIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { PointerEvent as ReactPointerEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import {
    canvasToFile,
    defaultCorners,
    detectDocumentCorners,
    loadOpenCv,
    warpToCorners,
} from '@/lib/document-scan';
import type { DocumentCorners, Point } from '@/lib/document-scan';

type CornerKey = keyof DocumentCorners;

/** Order doesn't matter for rendering drag handles, one per corner. */
const CORNER_KEYS: CornerKey[] = [
    'topLeft',
    'topRight',
    'bottomLeft',
    'bottomRight',
];

/**
 * The same four corners, but walked around the perimeter — top-left,
 * top-right, bottom-right, bottom-left — rather than `CORNER_KEYS`'s grid
 * order. An `<svg>` `<polygon>` connects its points in the order given, so
 * `CORNER_KEYS`'s order draws a bowtie (top-left to top-right, *then*
 * straight across to bottom-left, crossing the shape) instead of an outline.
 */
const PERIMETER_ORDER: CornerKey[] = [
    'topLeft',
    'topRight',
    'bottomRight',
    'bottomLeft',
];

/** The largest edge OpenCV will straighten a scan to, on its longer side. Big enough to stay readable, small enough that a phone photo doesn't balloon into a multi-megabyte upload once re-encoded. */
const MAX_OUTPUT_DIMENSION = 2000;

type Props = {
    file: File;
    onConfirm: (file: File) => void;
    onRetake: () => void;
    /**
     * Called when straightening throws, right before falling back to
     * uploading the original photo. Confirming still succeeds either way —
     * this is only so the failure is visible somewhere that outlives this
     * component (it unmounts the moment `onConfirm` runs), instead of only
     * in a phone browser's console that nobody's going to open.
     */
    onStraightenFailed?: (message: string) => void;
};

/**
 * The "make it look like a real scan" review step between taking a photo and
 * sending it: shows the photo with its detected (or default) corners as
 * draggable handles, then straightens the image into whatever quad the user
 * leaves behind.
 *
 * Corners are held as fractions of the image's own size (0–1 on each axis),
 * not pixels — that's what makes them trivial to render at any display size
 * and to drag with a plain percentage-based `left`/`top`. They're only
 * converted to the image's natural pixel coordinates at the point OpenCV
 * actually needs them, in `confirm()`.
 */
export function DocumentScanReview({
    file,
    onConfirm,
    onRetake,
    onStraightenFailed,
}: Props) {
    const t = useTranslation();
    const imgRef = useRef<HTMLImageElement>(null);
    const [imageUrl] = useState(() => URL.createObjectURL(file));
    const [stage, setStage] = useState<
        'detecting' | 'adjusting' | 'processing'
    >('detecting');
    const [corners, setCorners] = useState<DocumentCorners | null>(null);
    const [detectionFailed, setDetectionFailed] = useState(false);
    const draggingCornerRef = useRef<CornerKey | null>(null);
    const startedProcessingRef = useRef(false);

    useEffect(() => {
        return () => URL.revokeObjectURL(imageUrl);
    }, [imageUrl]);

    const handleImageLoad = async () => {
        const img = imgRef.current;

        if (!img || startedProcessingRef.current) {
            return;
        }

        startedProcessingRef.current = true;

        try {
            const cv = await loadOpenCv();
            const detected = detectDocumentCorners(cv, img);

            setCorners(
                toFractions(
                    detected ??
                        defaultCorners(img.naturalWidth, img.naturalHeight),
                    img,
                ),
            );
            setDetectionFailed(detected === null);
        } catch {
            // OpenCV failed to load or run (unsupported browser, out of
            // memory on a huge photo, offline asset blocked). Falling back
            // to a plain adjustable rectangle keeps the feature usable
            // rather than turning a processing failure into a dead end.
            setCorners(
                toFractions(
                    defaultCorners(img.naturalWidth, img.naturalHeight),
                    img,
                ),
            );
            setDetectionFailed(true);
        } finally {
            setStage('adjusting');
        }
    };

    // A blob: URL can decode fast enough that the `<img>` below is already
    // `complete` before React finishes attaching its `onLoad` handler — in
    // which case that event never fires and `detecting` would last forever.
    // `handleImageLoad`'s own `startedProcessingRef` guard keeps this from
    // double-running detection if `onLoad` does still fire afterwards.
    useEffect(() => {
        if (imgRef.current?.complete && imgRef.current.naturalWidth > 0) {
            void handleImageLoad();
        }
    });

    const startDrag = (corner: CornerKey) => (event: ReactPointerEvent) => {
        event.preventDefault();
        draggingCornerRef.current = corner;
        event.currentTarget.setPointerCapture(event.pointerId);
    };

    const drag = (event: ReactPointerEvent) => {
        const corner = draggingCornerRef.current;
        const container = imgRef.current?.parentElement;

        if (!corner || !container || !corners) {
            return;
        }

        const rect = container.getBoundingClientRect();

        setCorners({
            ...corners,
            [corner]: {
                x: clamp01((event.clientX - rect.left) / rect.width),
                y: clamp01((event.clientY - rect.top) / rect.height),
            },
        });
    };

    const endDrag = () => {
        draggingCornerRef.current = null;
    };

    const confirm = async () => {
        const img = imgRef.current;

        if (!img || !corners) {
            return;
        }

        setStage('processing');

        try {
            await loadOpenCv();
            const naturalCorners = toNatural(corners, img);
            const { width, height } = outputSize(naturalCorners);
            const canvas = warpToCorners(img, naturalCorners, width, height);
            const scanned = await canvasToFile(
                canvas,
                scannedFilename(file.name),
            );

            onConfirm(scanned ?? file);
        } catch (error) {
            // Straightening failed — the original photo is still a
            // perfectly usable attachment, just not a cropped one. Reported
            // upward rather than swallowed outright, so a real failure here
            // is visible somewhere other than a phone browser's console
            // that nobody's going to open.
            console.error('Failed to straighten the scan:', error);
            onStraightenFailed?.(
                error instanceof Error ? error.message : String(error),
            );
            onConfirm(file);
        }
    };

    return (
        <div className="flex w-full max-w-sm flex-col items-center gap-4">
            {/*
             * The image stays mounted through every stage, `detecting`
             * included — it's the image's own `onLoad` that drives the
             * transition out of `detecting` in the first place. Rendering it
             * only once detection finished would mean it never starts.
             */}
            <div className="relative w-full touch-none overflow-hidden rounded-md border select-none">
                <img
                    ref={imgRef}
                    src={imageUrl}
                    alt=""
                    className="block w-full"
                    onLoad={handleImageLoad}
                />
                {stage === 'detecting' && (
                    <div className="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-background/85">
                        <Spinner className="size-6" />
                        <p className="text-sm text-muted-foreground">
                            {t('capture.preparing_scanner')}
                        </p>
                    </div>
                )}
                {corners && (
                    <svg
                        className="pointer-events-none absolute inset-0 size-full"
                        viewBox="0 0 100 100"
                        preserveAspectRatio="none"
                    >
                        <polygon
                            points={PERIMETER_ORDER.map(
                                (key) =>
                                    `${corners[key].x * 100},${corners[key].y * 100}`,
                            ).join(' ')}
                            className="fill-primary/20 stroke-primary"
                            strokeWidth={0.6}
                        />
                    </svg>
                )}
                {corners &&
                    CORNER_KEYS.map((key) => (
                        <div
                            key={key}
                            onPointerDown={startDrag(key)}
                            onPointerMove={drag}
                            onPointerUp={endDrag}
                            className="absolute size-7 -translate-x-1/2 -translate-y-1/2 touch-none rounded-full border-2 border-primary bg-background shadow"
                            style={{
                                left: `${corners[key].x * 100}%`,
                                top: `${corners[key].y * 100}%`,
                            }}
                        />
                    ))}
            </div>

            {stage !== 'detecting' && (
                <>
                    {detectionFailed ? (
                        <p className="flex items-center gap-1.5 text-center text-sm font-medium text-amber-600 dark:text-amber-500">
                            <TriangleAlertIcon className="size-4 shrink-0" />
                            {t('capture.detection_failed')}
                        </p>
                    ) : (
                        <p className="text-center text-xs text-muted-foreground">
                            {t('capture.adjust_corners_hint')}
                        </p>
                    )}

                    <div className="flex w-full flex-col gap-2">
                        <Button
                            onClick={confirm}
                            disabled={stage === 'processing'}
                            className="w-full"
                        >
                            {stage === 'processing' && <Spinner />}
                            {t('capture.confirm_scan_button')}
                        </Button>
                        <div className="flex gap-2">
                            <Button
                                variant="outline"
                                onClick={onRetake}
                                disabled={stage === 'processing'}
                                className="flex-1"
                            >
                                {t('capture.retake_button')}
                            </Button>
                            <Button
                                variant="ghost"
                                onClick={() => onConfirm(file)}
                                disabled={stage === 'processing'}
                                className="flex-1"
                            >
                                {t('capture.use_original_button')}
                            </Button>
                        </div>
                    </div>
                </>
            )}
        </div>
    );
}

/** @returns `value` clamped to the [0, 1] range. */
function clamp01(value: number): number {
    return Math.min(1, Math.max(0, value));
}

/** @returns `corners`, each divided by `image`'s natural size. */
function toFractions(
    corners: DocumentCorners,
    image: HTMLImageElement,
): DocumentCorners {
    return mapCorners(corners, (point) => ({
        x: point.x / image.naturalWidth,
        y: point.y / image.naturalHeight,
    }));
}

/** @returns `corners`, each multiplied by `image`'s natural size. */
function toNatural(
    corners: DocumentCorners,
    image: HTMLImageElement,
): DocumentCorners {
    return mapCorners(corners, (point) => ({
        x: point.x * image.naturalWidth,
        y: point.y * image.naturalHeight,
    }));
}

/** @returns `corners` with `transform` applied to each point. */
function mapCorners(
    corners: DocumentCorners,
    transform: (point: Point) => Point,
): DocumentCorners {
    return {
        topLeft: transform(corners.topLeft),
        topRight: transform(corners.topRight),
        bottomLeft: transform(corners.bottomLeft),
        bottomRight: transform(corners.bottomRight),
    };
}

/**
 * The straightened image's target size: the corners' own geometry (the
 * longer of the two top/bottom edges, the longer of the two left/right
 * edges), scaled down to `MAX_OUTPUT_DIMENSION` if needed. Using the actual
 * marked rectangle's proportions — rather than a fixed size — is what keeps
 * a tall receipt tall and a landscape certificate wide, instead of
 * stretching everything to the same shape.
 *
 * @param corners The document's corners, in the source image's own pixel coordinates.
 *
 * @returns The output width and height, in pixels.
 */
function outputSize(corners: DocumentCorners): {
    width: number;
    height: number;
} {
    const width = Math.max(
        distance(corners.topLeft, corners.topRight),
        distance(corners.bottomLeft, corners.bottomRight),
    );
    const height = Math.max(
        distance(corners.topLeft, corners.bottomLeft),
        distance(corners.topRight, corners.bottomRight),
    );

    const scale = Math.min(1, MAX_OUTPUT_DIMENSION / Math.max(width, height));

    return {
        width: Math.max(1, Math.round(width * scale)),
        height: Math.max(1, Math.round(height * scale)),
    };
}

/** @returns The distance between two points. */
function distance(a: Point, b: Point): number {
    return Math.hypot(a.x - b.x, a.y - b.y);
}

/** @returns `originalName` with its extension replaced by a "-scan.jpg" suffix. */
function scannedFilename(originalName: string): string {
    return `${originalName.replace(/\.\w+$/, '')}-scan.jpg`;
}
