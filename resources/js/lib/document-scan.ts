/**
 * Detect a document in a photo and straighten it, for the phone capture page
 * (ARC-105).
 *
 * Detection is ours, over OpenCV.js — see `document-scan-runtime.ts`. It was
 * jscanify's until ARC-117; that library names corners by which quadrant of the
 * shape they land in, which loses a corner outright on a rotated page. The
 * perspective warp is still jscanify's
 * (https://github.com/ColonelParrot/jscanify, MIT License), which was never the
 * part that was wrong.
 *
 * The geometry lives here and the OpenCV calls live in the runtime, so
 * everything that decides *whether a quad is a document* can be tested without
 * a 13MB WASM module: this module is safe to import anywhere.
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
     * @param minAreaRatio Smallest share of the image a detection may cover; defaults to the framed-photo floor, which the viewfinder lowers.
     *
     * @returns The document's four corners in `image`'s own pixel
     * coordinates, or `null` if nothing convincing was found.
     */
    detectCorners(
        image: ScanImage,
        minAreaRatio?: number,
    ): DocumentCorners | null;

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

/** Above this share of the frame, the detector found the photo's edge, not a document. */
const SUSPICIOUS_FULL_FRAME_AREA_RATIO = 0.92;

/**
 * Below this share of the frame, the detector found something printed on the
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

/**
 * The same floor for the live viewfinder, which is a different situation.
 *
 * A photo is framed before it is taken, so a page occupying a quarter of it is
 * already suspicious. A viewfinder is aimed: the page crosses every size on
 * the way in, and refusing it until it is nearly framed means the outline only
 * appears once it is no longer needed. The full-frame ceiling is shared —
 * that one means the same thing in both.
 */
export const VIEWFINDER_MIN_AREA_RATIO = 0.1;

/**
 * Beyond this ratio between the longest and shortest side, the quad is a band
 * rather than a page — a rule under a letterhead, the edge of a desk.
 *
 * Loose on purpose: perspective alone stretches a page a long way, and a
 * document that is genuinely narrow is somebody's receipt.
 */
const MAX_SIDE_RATIO = 8;

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

/** @returns The four side lengths of `corners`, walking the perimeter. */
function sideLengths(corners: DocumentCorners): number[] {
    const points = [
        corners.topLeft,
        corners.topRight,
        corners.bottomRight,
        corners.bottomLeft,
    ];

    return points.map((point, index) => {
        const next = points[(index + 1) % points.length];

        return Math.hypot(next.x - point.x, next.y - point.y);
    });
}

/**
 * Whether a quad's corners are named in an order that walks a convex
 * perimeter.
 *
 * Every candidate is four points that approximate *some* closed contour, which
 * is not the same as four points that bound a page. A hand, a folded corner or
 * a shadow spilling off the sheet all approximate to four points; what they do
 * not do is come back convex. Turning consistently in one direction at all
 * four corners is the cheapest description of "a sheet of paper seen from an
 * angle" there is, and perspective cannot break it — a projected rectangle
 * stays convex from every viewpoint.
 *
 * It also catches an ordering mistake, which is worth as much: four good points
 * named in the wrong order are a bowtie, and a bowtie reverses its turn twice.
 *
 * @param corners The quad to test, named in perimeter order.
 *
 * @returns Whether all four corners turn the same way.
 */
export function isConvexQuad(corners: DocumentCorners): boolean {
    const points = [
        corners.topLeft,
        corners.topRight,
        corners.bottomRight,
        corners.bottomLeft,
    ];
    let winding = 0;

    for (let index = 0; index < points.length; index++) {
        const from = points[index];
        const at = points[(index + 1) % points.length];
        const to = points[(index + 2) % points.length];
        const cross =
            (at.x - from.x) * (to.y - at.y) - (at.y - from.y) * (to.x - at.x);

        // Exactly straight is three points on a line: a triangle wearing a
        // fourth vertex, or two corners that landed on top of each other.
        if (cross === 0) {
            return false;
        }

        const turn = Math.sign(cross);

        if (winding === 0) {
            winding = turn;
        } else if (turn !== winding) {
            return false;
        }
    }

    return true;
}

/**
 * Name four unordered points topLeft/topRight/bottomLeft/bottomRight.
 *
 * The corners come off a contour in whatever order the tracer walked it, and
 * naming them by which quadrant of the shape they fall in — the way jscanify
 * does — fails exactly when a page is rotated: a true corner sits on a quadrant
 * boundary, a pixel of jitter moves it across, and a quadrant left empty
 * produces a quad with a corner missing (ARC-117).
 *
 * Sorting by angle around the centroid has no such boundary. With y pointing
 * down, ascending `atan2` walks left, top, right, bottom — the perimeter,
 * clockwise on screen — for any rotation at all. Which vertex is *called*
 * top-left is then whichever sits nearest the image origin.
 *
 * At exactly 45° there is genuinely no top-left, and two vertices tie. Either
 * answer is a correct quad; the outline is identical, and a straightened page
 * comes out turned a quarter. That is inherent to the question, not to this.
 *
 * @param points Four points bounding the document, in any order.
 *
 * @returns The same four points named, or `null` if there were not exactly four.
 */
export function orderCorners(points: Point[]): DocumentCorners | null {
    if (points.length !== 4) {
        return null;
    }

    const centre = {
        x: points.reduce((sum, point) => sum + point.x, 0) / points.length,
        y: points.reduce((sum, point) => sum + point.y, 0) / points.length,
    };

    const clockwise = [...points].sort(
        (first, second) =>
            Math.atan2(first.y - centre.y, first.x - centre.x) -
            Math.atan2(second.y - centre.y, second.x - centre.x),
    );

    let start = 0;

    for (let index = 1; index < clockwise.length; index++) {
        const candidate = clockwise[index];

        if (
            candidate.x + candidate.y <
            clockwise[start].x + clockwise[start].y
        ) {
            start = index;
        }
    }

    const [topLeft, topRight, bottomRight, bottomLeft] = clockwise.map(
        (_, offset) => clockwise[(start + offset) % clockwise.length],
    );

    return { topLeft, topRight, bottomRight, bottomLeft };
}

/**
 * Whether a detected quad is the wrong size or the wrong shape to be the
 * document.
 *
 * The detector answers "the largest four-sided convex contour in this image",
 * which is closer to "the page" than the largest contour of any shape was, but
 * still not the same question. It misses in three ways, and none of them
 * announces itself — four clean corners come back every time:
 *
 * - Too big: the image's own border wins, and confirming crops nothing.
 * - Too small: a box printed on the page — a totals table, a framed payment
 *   block — is a crisp convex rectangle, so it wins on area, and confirming
 *   files that box instead of the document (ARC-110).
 * - The wrong shape: a rule under a letterhead, or the edge of the desk, is a
 *   convex quadrilateral of respectable area whose sides are too far apart in
 *   length to be a sheet of paper.
 *
 * Refusing here puts the corners back at their default for the user to drag,
 * which is what an image with no detection at all already does.
 *
 * @param corners A detected quad, in the image's own pixel coordinates.
 * @param imageWidth The image's width, in pixels.
 * @param imageHeight The image's height, in pixels.
 * @param minAreaRatio Smallest share of the image the quad may cover; the viewfinder allows less than a framed photo does.
 *
 * @returns Whether the quad should be refused rather than offered.
 */
export function isImplausibleDocument(
    corners: DocumentCorners,
    imageWidth: number,
    imageHeight: number,
    minAreaRatio: number = SUSPICIOUS_INNER_DETAIL_AREA_RATIO,
): boolean {
    const share = quadArea(corners) / (imageWidth * imageHeight);

    if (share > SUSPICIOUS_FULL_FRAME_AREA_RATIO || share < minAreaRatio) {
        return true;
    }

    const sides = sideLengths(corners);
    const shortest = Math.min(...sides);

    return shortest === 0 || Math.max(...sides) / shortest > MAX_SIDE_RATIO;
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
 * Consecutive passes that may find nothing before the outline is taken down.
 *
 * Detection is a fresh guess every pass, and a guess about a moving picture
 * misses for reasons that have nothing to do with the page: a frame caught
 * mid-exposure, a hand crossing a corner, the paper leaving the frame for an
 * instant. Clearing on the first miss turns that into a strobe.
 *
 * Holding the last accepted quad through a miss or two costs an outline that
 * lags reality for a fraction of a second, which is what a viewfinder outline
 * is anyway — it is a guide for aiming, and the crop is decided later, on the
 * frame the shutter keeps.
 */
export const VIEWFINDER_MISS_TOLERANCE = 3;

/**
 * How far the drawn outline moves toward each new detection, 0 to 1.
 *
 * Detection on successive frames of the same still page lands a few pixels
 * apart, which reads as a jittering outline. Averaging against the previous
 * one absorbs that. Too low and the outline swims after the page instead of
 * sitting on it, which is why this is nearer a half than a tenth.
 */
export const VIEWFINDER_SMOOTHING = 0.5;

/**
 * Move `previous` a fraction of the way toward `next`.
 *
 * @param previous The outline currently drawn.
 * @param next The quad just detected.
 * @param weight Share of the distance to travel, 0 (stay) to 1 (jump).
 *
 * @returns The quad to draw.
 */
export function smoothCorners(
    previous: DocumentCorners,
    next: DocumentCorners,
    weight: number,
): DocumentCorners {
    const blend = (from: Point, to: Point): Point => ({
        x: from.x + (to.x - from.x) * weight,
        y: from.y + (to.y - from.y) * weight,
    });

    return {
        topLeft: blend(previous.topLeft, next.topLeft),
        topRight: blend(previous.topRight, next.topRight),
        bottomLeft: blend(previous.bottomLeft, next.bottomLeft),
        bottomRight: blend(previous.bottomRight, next.bottomRight),
    };
}

/**
 * Whether two quads are far enough apart that the outline should jump rather
 * than slide.
 *
 * Smoothing is right for the same page drifting under the camera and wrong for
 * a different page: averaging across a genuine change drags the outline through
 * the space between two documents, drawing a quad that matches neither. Past
 * this share of the frame, the detection is treated as a new subject.
 *
 * @param previous The outline currently drawn.
 * @param next The quad just detected.
 * @param frameWidth Width of the frame both are expressed in, in pixels.
 * @param frameHeight Height of the frame both are expressed in, in pixels.
 *
 * @returns Whether `next` describes something other than what `previous` did.
 */
export function isDifferentSubject(
    previous: DocumentCorners,
    next: DocumentCorners,
    frameWidth: number,
    frameHeight: number,
): boolean {
    const diagonal = Math.hypot(frameWidth, frameHeight);

    if (diagonal === 0) {
        return true;
    }

    const keys: (keyof DocumentCorners)[] = [
        'topLeft',
        'topRight',
        'bottomLeft',
        'bottomRight',
    ];

    return keys.some(
        (key) =>
            Math.hypot(
                next[key].x - previous[key].x,
                next[key].y - previous[key].y,
            ) /
                diagonal >
            VIEWFINDER_JUMP_RATIO,
    );
}

/** Past this share of the frame diagonal, a corner has moved to a different subject. */
const VIEWFINDER_JUMP_RATIO = 0.25;

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
