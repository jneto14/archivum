/**
 * Detect a document in a photo and straighten it, for the phone capture page
 * (ARC-105). Detection and warping are jscanify's
 * (https://github.com/ColonelParrot/jscanify, MIT License).
 *
 * Nothing here imports OpenCV.js: it lives in `document-scan-runtime.ts`,
 * loaded on demand by `loadScanner()`, so the ~13MB only arrives once a
 * photo actually needs processing. That indirection is load-bearing —
 * `import()`ing OpenCV.js itself throws. See .ai/rules/lib.md.
 */
export type Point = { x: number; y: number };

export type DocumentCorners = {
    topLeft: Point;
    topRight: Point;
    bottomLeft: Point;
    bottomRight: Point;
};

/**
 * What detection and warping accept: a photo, or a canvas holding a frame
 * lifted off a live camera.
 */
export type ScanImage = HTMLImageElement | HTMLCanvasElement;

/** The operations that need an initialized OpenCV.js behind them. */
export type Scanner = {
    /**
     * @param image The photo or frame to search.
     *
     * @returns The document's four corners in `image`'s own pixel
     * coordinates, or `null` if nothing convincing was found.
     */
    detectCorners(image: ScanImage): DocumentCorners | null;

    /**
     * @param image The photo to straighten.
     * @param corners The document's corners within `image`, in its own pixel coordinates.
     * @param outputWidth Desired output width, in pixels.
     * @param outputHeight Desired output height, in pixels.
     *
     * @returns A canvas containing the straightened image.
     */
    warp(
        image: ScanImage,
        corners: DocumentCorners,
        outputWidth: number,
        outputHeight: number,
    ): HTMLCanvasElement;
};

/** @returns The pixel size `image` really has, whatever kind of element it is. */
export function intrinsicSize(image: ScanImage): {
    width: number;
    height: number;
} {
    return image instanceof HTMLCanvasElement
        ? { width: image.width, height: image.height }
        : { width: image.naturalWidth, height: image.naturalHeight };
}

let scannerPromise: Promise<Scanner> | null = null;

/**
 * Load OpenCV.js and jscanify, once, and return the operations that need
 * them. Failures aren't cached, so a later photo can try again.
 *
 * @returns A scanner ready to detect and straighten.
 */
export function loadScanner(): Promise<Scanner> {
    scannerPromise ??= import('@/lib/document-scan-runtime')
        .then((runtime) => runtime.createScanner())
        .catch((error: unknown) => {
            scannerPromise = null;

            throw error;
        });

    return scannerPromise;
}

/**
 * The fallback quad when detection finds nothing, inset so the drag handles
 * start visibly inside the photo.
 *
 * @param width Image width in pixels.
 * @param height Image height in pixels.
 *
 * @returns The four corners of an inset rectangle.
 */
export function defaultCorners(width: number, height: number): DocumentCorners {
    const insetX = width * 0.08;
    const insetY = height * 0.08;

    return {
        topLeft: { x: insetX, y: insetY },
        topRight: { x: width - insetX, y: insetY },
        bottomLeft: { x: insetX, y: height - insetY },
        bottomRight: { x: width - insetX, y: height - insetY },
    };
}

/** Above this share of the frame, jscanify found the photo's edge, not a document. */
const SUSPICIOUS_FULL_FRAME_AREA_RATIO = 0.92;

/**
 * Below this share of the frame, jscanify found something printed on the
 * document rather than the document.
 *
 * Deliberately low. Somebody photographing a page to file it fills most of the
 * frame with it, so a real page is rarely near this — but the threshold is a
 * trade either way, and the two directions cost different amounts. Refusing a
 * real detection means the corners start at their default and the user drags
 * them, which is the flow anyway. Accepting a false one means a confidently
 * wrong crop, which has to be noticed before it is confirmed or the document is
 * filed as a fragment of itself.
 */
const SUSPICIOUS_INNER_DETAIL_AREA_RATIO = 0.25;

/** @returns The area of the quadrilateral `corners`, via the shoelace formula. */
function quadArea(corners: DocumentCorners): number {
    const points = [
        corners.topLeft,
        corners.topRight,
        corners.bottomRight,
        corners.bottomLeft,
    ];
    let sum = 0;

    for (let i = 0; i < points.length; i++) {
        const { x: x1, y: y1 } = points[i];
        const { x: x2, y: y2 } = points[(i + 1) % points.length];
        sum += x1 * y2 - x2 * y1;
    }

    return Math.abs(sum) / 2;
}

/**
 * Whether a detected quad is too big or too small to be the document.
 *
 * jscanify answers "the largest closed shape in this photo", which is not the
 * same question as "the page". It misses in both directions, and neither miss
 * announces itself — four clean corners come back either way:
 *
 * - Too big: the photo's own border wins, and confirming crops nothing.
 * - Too small: a box printed on the page — a totals table, a framed payment
 *   block — has crisper edges than a sheet of paper on a desk does, so it wins
 *   on area, and confirming files that box instead of the document (ARC-110).
 *
 * Refusing here puts the corners back at their default for the user to drag,
 * which is what a photo with no detection at all already does.
 *
 * @param corners A detected quad, in the image's own pixel coordinates.
 * @param imageWidth The image's width, in pixels.
 * @param imageHeight The image's height, in pixels.
 *
 * @returns Whether the quad should be refused rather than offered.
 */
export function isImplausibleDocument(
    corners: DocumentCorners,
    imageWidth: number,
    imageHeight: number,
): boolean {
    const share = quadArea(corners) / (imageWidth * imageHeight);

    return (
        share > SUSPICIOUS_FULL_FRAME_AREA_RATIO ||
        share < SUSPICIOUS_INNER_DETAIL_AREA_RATIO
    );
}

/**
 * Longest edge of the frame the live viewfinder runs detection on.
 *
 * Detection is a guide drawn over a moving picture, not the crop that gets
 * filed — that is redone at full resolution on the frame the shutter keeps. At
 * this size a pass costs a few tens of milliseconds on a phone, which is what
 * makes an outline that follows the paper possible at all; at 1080p it would
 * be slower than the frames it is trying to describe.
 */
export const VIEWFINDER_DETECTION_WIDTH = 480;

/**
 * Move a quad from one image's pixel coordinates into another's.
 *
 * The viewfinder detects on a downscaled frame and draws on the full-size one,
 * so the corners have to be carried back up before anything can be drawn with
 * them.
 *
 * @param corners The quad to move, in the source image's pixels.
 * @param from The size the corners are expressed in.
 * @param to The size to express them in.
 *
 * @returns The same quad in `to`'s pixel coordinates.
 */
export function scaleCorners(
    corners: DocumentCorners,
    from: { width: number; height: number },
    to: { width: number; height: number },
): DocumentCorners {
    const scaleX = from.width === 0 ? 0 : to.width / from.width;
    const scaleY = from.height === 0 ? 0 : to.height / from.height;
    const move = (point: Point): Point => ({
        x: point.x * scaleX,
        y: point.y * scaleY,
    });

    return {
        topLeft: move(corners.topLeft),
        topRight: move(corners.topRight),
        bottomLeft: move(corners.bottomLeft),
        bottomRight: move(corners.bottomRight),
    };
}

/**
 * The quad as an SVG `points` attribute.
 *
 * In perimeter order, or the polygon draws itself as a bowtie — the corners are
 * named in reading order, which is not the order you walk them in.
 *
 * @param corners The quad to draw.
 *
 * @returns A `points` value in the same coordinates the corners came in.
 */
export function cornersToPolygon(corners: DocumentCorners): string {
    return [
        corners.topLeft,
        corners.topRight,
        corners.bottomRight,
        corners.bottomLeft,
    ]
        .map((point) => `${point.x},${point.y}`)
        .join(' ');
}

/**
 * Encode a canvas as a `File`, so a warped scan uploads through the same
 * path a raw camera photo does.
 *
 * @param canvas The canvas to encode.
 * @param filename The filename to give the resulting file.
 *
 * @returns The encoded file, or `null` if the canvas failed to encode.
 */
export function canvasToFile(
    canvas: HTMLCanvasElement,
    filename: string,
): Promise<File | null> {
    return new Promise((resolve) => {
        canvas.toBlob(
            (blob) =>
                resolve(
                    blob
                        ? new File([blob], filename, { type: 'image/jpeg' })
                        : null,
                ),
            'image/jpeg',
            0.92,
        );
    });
}
