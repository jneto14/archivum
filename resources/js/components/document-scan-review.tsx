import { TriangleAlertIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { PointerEvent as ReactPointerEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { canvasToFile, defaultCorners, loadScanner } from '@/lib/document-scan';
import type { DocumentCorners, Point } from '@/lib/document-scan';

type CornerKey = keyof DocumentCorners;

const CORNER_KEYS: CornerKey[] = [
    'topLeft',
    'topRight',
    'bottomLeft',
    'bottomRight',
];

/** `CORNER_KEYS` in grid order would draw the polygon as a bowtie. */
const PERIMETER_ORDER: CornerKey[] = [
    'topLeft',
    'topRight',
    'bottomRight',
    'bottomLeft',
];

/** Longest edge of a straightened scan: readable without a huge re-encode. */
const MAX_OUTPUT_DIMENSION = 2000;

/** Diameter, magnification and edge margin of the magnifier shown while dragging. */
const LOUPE_SIZE = 104;
const LOUPE_ZOOM = 2.5;
const LOUPE_GAP = 12;

/** Past this share of the width, the magnifier moves to the opposite side. */
const LOUPE_SIDE_THRESHOLD = 0.5;

type Props = {
    file: File;
    onConfirm: (file: File) => void;
    onRetake: () => void;
    /** Reports a straightening failure somewhere that outlives this component, which unmounts as soon as `onConfirm` runs. */
    onStraightenFailed?: (message: string) => void;
};

/**
 * The review step between taking a photo and sending it: the photo with its
 * detected (or default) corners as draggable handles, straightened into
 * whatever quad the user leaves behind.
 *
 * Corners are held as fractions of the image's size, so they render and drag
 * as plain percentages; `confirm()` converts them to pixels for OpenCV.
 */
export function DocumentScanReview({
    file,
    onConfirm,
    onRetake,
    onStraightenFailed,
}: Props) {
    const t = useTranslation();
    const imgRef = useRef<HTMLImageElement>(null);
    const containerRef = useRef<HTMLDivElement>(null);
    const [imageUrl] = useState(() => URL.createObjectURL(file));
    const [stage, setStage] = useState<
        'detecting' | 'adjusting' | 'processing'
    >('detecting');
    const [corners, setCorners] = useState<DocumentCorners | null>(null);
    const [detectionFailed, setDetectionFailed] = useState(false);
    const draggingCornerRef = useRef<CornerKey | null>(null);
    // Where the pointer and the corner each were when the drag started, so
    // the corner tracks the finger's movement instead of jumping under it.
    const dragOriginRef = useRef<{ pointer: Point; corner: Point } | null>(
        null,
    );
    // Mirrors `draggingCornerRef` for rendering the magnifier; the ref stays
    // the one the move handler reads, since it can't go stale mid-gesture.
    const [draggingCorner, setDraggingCorner] = useState<CornerKey | null>(
        null,
    );
    const [containerSize, setContainerSize] = useState<{
        width: number;
        height: number;
    } | null>(null);
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
            const scanner = await loadScanner();
            const detected = scanner.detectCorners(img);

            setCorners(
                toFractions(
                    detected ??
                        defaultCorners(img.naturalWidth, img.naturalHeight),
                    img,
                ),
            );
            setDetectionFailed(detected === null);
        } catch (error) {
            // An adjustable rectangle keeps the feature usable rather than
            // making a processing failure a dead end.
            console.error('Failed to detect the document corners:', error);
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

    // A blob: URL can decode before React attaches `onLoad`, in which case
    // that event never fires and `detecting` would last forever.
    useEffect(() => {
        if (imgRef.current?.complete && imgRef.current.naturalWidth > 0) {
            void handleImageLoad();
        }
    });

    const startDrag = (corner: CornerKey) => (event: ReactPointerEvent) => {
        const container = containerRef.current;

        if (!container || !corners) {
            return;
        }

        event.preventDefault();
        const rect = container.getBoundingClientRect();

        draggingCornerRef.current = corner;
        dragOriginRef.current = {
            pointer: { x: event.clientX, y: event.clientY },
            corner: corners[corner],
        };
        setDraggingCorner(corner);
        setContainerSize({ width: rect.width, height: rect.height });
        event.currentTarget.setPointerCapture(event.pointerId);
    };

    const drag = (event: ReactPointerEvent) => {
        const corner = draggingCornerRef.current;
        const origin = dragOriginRef.current;
        const container = containerRef.current;

        if (!corner || !origin || !container || !corners) {
            return;
        }

        const rect = container.getBoundingClientRect();

        setCorners({
            ...corners,
            [corner]: {
                x: clamp01(
                    origin.corner.x +
                        (event.clientX - origin.pointer.x) / rect.width,
                ),
                y: clamp01(
                    origin.corner.y +
                        (event.clientY - origin.pointer.y) / rect.height,
                ),
            },
        });
    };

    const endDrag = () => {
        draggingCornerRef.current = null;
        dragOriginRef.current = null;
        setDraggingCorner(null);
    };

    const confirm = async () => {
        const img = imgRef.current;

        if (!img || !corners) {
            return;
        }

        setStage('processing');

        try {
            const scanner = await loadScanner();
            const naturalCorners = toNatural(corners, img);
            const { width, height } = outputSize(naturalCorners);
            const canvas = scanner.warp(img, naturalCorners, width, height);
            const scanned = await canvasToFile(
                canvas,
                scannedFilename(file.name),
            );

            onConfirm(scanned ?? file);
        } catch (error) {
            // The original photo is still a usable attachment, just not a
            // cropped one — but say so rather than failing silently.
            console.error('Failed to straighten the scan:', error);
            onStraightenFailed?.(
                error instanceof Error ? error.message : String(error),
            );
            onConfirm(file);
        }
    };

    return (
        <div className="flex w-full max-w-sm flex-col items-center gap-4">
            {/* Stays mounted while detecting: its own onLoad starts that. */}
            <div
                ref={containerRef}
                className="relative w-full touch-none overflow-hidden rounded-md border select-none"
            >
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
                        // The hit area is deliberately larger than the dot:
                        // a fingertip covers far more than 28px.
                        <div
                            key={key}
                            onPointerDown={startDrag(key)}
                            onPointerMove={drag}
                            onPointerUp={endDrag}
                            onPointerCancel={endDrag}
                            className="absolute flex size-12 -translate-x-1/2 -translate-y-1/2 touch-none items-center justify-center"
                            style={{
                                left: `${corners[key].x * 100}%`,
                                top: `${corners[key].y * 100}%`,
                            }}
                        >
                            <span className="size-7 rounded-full border-2 border-primary bg-background shadow" />
                        </div>
                    ))}
                {corners && draggingCorner && containerSize && (
                    <Loupe
                        imageUrl={imageUrl}
                        corner={corners[draggingCorner]}
                        containerSize={containerSize}
                    />
                )}
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

/**
 * A magnified view of the photo around the corner being dragged — without it,
 * the one spot you are trying to place is the one spot your finger covers.
 *
 * It sits in a top corner of the photo rather than following the finger,
 * moving to the other side when the drag reaches its half. A magnifier that
 * tracks the finger has nowhere left to go once the corner nears an edge,
 * which is exactly when it is needed most.
 */
function Loupe({
    imageUrl,
    corner,
    containerSize,
}: {
    imageUrl: string;
    corner: Point;
    containerSize: { width: number; height: number };
}) {
    const center = {
        x: corner.x * containerSize.width,
        y: corner.y * containerSize.height,
    };
    const onTheLeft = corner.x > LOUPE_SIDE_THRESHOLD;

    return (
        <div
            className="pointer-events-none absolute z-10 overflow-hidden rounded-full border-2 border-primary bg-background shadow-lg"
            style={{
                width: LOUPE_SIZE,
                height: LOUPE_SIZE,
                top: LOUPE_GAP,
                left: onTheLeft ? LOUPE_GAP : undefined,
                right: onTheLeft ? undefined : LOUPE_GAP,
            }}
        >
            <img
                src={imageUrl}
                alt=""
                className="absolute max-w-none"
                style={{
                    width: containerSize.width * LOUPE_ZOOM,
                    height: containerSize.height * LOUPE_ZOOM,
                    left: LOUPE_SIZE / 2 - center.x * LOUPE_ZOOM,
                    top: LOUPE_SIZE / 2 - center.y * LOUPE_ZOOM,
                }}
            />
            <div className="absolute inset-x-0 top-1/2 h-px bg-primary/70" />
            <div className="absolute inset-y-0 left-1/2 w-px bg-primary/70" />
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
 * The output size, taken from the marked quad's own longest edges so a tall
 * receipt stays tall and a wide certificate stays wide.
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
