/**
 * Client-side "make it look like a real scan" pipeline for the phone capture
 * page (ARC-105): detect a document's edges in a captured photo, let the user
 * confirm or drag the corners into place, then straighten the photo into the
 * rectangle they marked.
 *
 * The detection/warp algorithm is jscanify's own
 * (https://github.com/ColonelParrot/jscanify, MIT License), used directly via
 * its `jscanify/client` export. That subpath is a plain browser build with no
 * dependency beyond a global `cv` — the package's *default* export instead
 * resolves to a Node build requiring `canvas`/`jsdom`, which is why the import
 * below is `jscanify/client` and not bare `jscanify`.
 *
 * jscanify's browser build expects a `<script>`-tag OpenCV.js that sets
 * `window.cv` itself. This app loads OpenCV.js as an ES module instead
 * (`@techstark/opencv-js`, dynamically — it's ~13MB, so only the first photo
 * that actually needs processing pays for it), so `loadOpenCv()` assigns the
 * loaded module to `window.cv` to satisfy that expectation.
 */
import JScanify from 'jscanify/client';

export type Point = { x: number; y: number };

export type DocumentCorners = {
    topLeft: Point;
    topRight: Point;
    bottomLeft: Point;
    bottomRight: Point;
};

type Mat = { delete(): void };

/**
 * The narrow slice of the OpenCV.js API this module calls directly (jscanify
 * calls the rest itself, via the global `window.cv` set below).
 */
type OpenCv = {
    Mat: new () => Mat;
    imread(source: HTMLCanvasElement | HTMLImageElement): Mat;
};

let openCvPromise: Promise<OpenCv> | null = null;

/**
 * Load OpenCV.js, once, and expose it as `window.cv` for jscanify's browser
 * build to find. Later calls reuse the same in-flight or already finished
 * load rather than injecting the module again.
 *
 * Mirrors the readiness dance `@techstark/opencv-js`'s own README documents:
 * depending on the build, the default export is either already a `Mat`-
 * bearing object, a `Promise` of one, or an object that fires
 * `onRuntimeInitialized` once its WASM has finished loading.
 *
 * A failed load is *not* cached: this is a ~13MB WASM download over
 * whatever connection the phone has, and caching a rejected promise would
 * mean one dropped request permanently breaks every photo taken for the
 * rest of that page's lifetime — always falling back to the unprocessed
 * original, with the only sign being a console error nobody's looking at.
 * Each call after a failure gets a fresh attempt instead.
 *
 * @returns The ready-to-use OpenCV.js module.
 */
export function loadOpenCv(): Promise<OpenCv> {
    if (openCvPromise === null) {
        openCvPromise = import('@techstark/opencv-js')
            .then(
                (imported) =>
                    new Promise<OpenCv>((resolve) => {
                        const cvModule = (imported.default ??
                            imported) as OpenCv & {
                            then?: (onFulfilled: (cv: OpenCv) => void) => void;
                            onRuntimeInitialized?: () => void;
                        };

                        const ready = (cv: OpenCv) => {
                            // `@techstark/opencv-js` already declares `cv`
                            // as a global itself (see its `_cv.d.ts`) —
                            // this is that same global, assigned so
                            // jscanify's browser build (written for a
                            // `<script>`-tag OpenCV.js) can find it. The
                            // double cast is because our own narrower
                            // `OpenCv` type has far fewer members than
                            // theirs.
                            globalThis.cv =
                                cv as unknown as typeof globalThis.cv;
                            resolve(cv);
                        };

                        if (typeof cvModule.then === 'function') {
                            cvModule.then(ready);
                        } else if (cvModule.Mat) {
                            ready(cvModule);
                        } else {
                            cvModule.onRuntimeInitialized = () =>
                                ready(cvModule);
                        }
                    }),
            )
            .catch((error: unknown) => {
                openCvPromise = null;

                throw error;
            });
    }

    return openCvPromise;
}

let scanner: JScanify | null = null;

/** @returns A shared jscanify instance — it holds no state of its own beyond `window.cv`, so one is enough for the page's lifetime. */
function getScanner(): JScanify {
    scanner ??= new JScanify();

    return scanner;
}

/**
 * The full image as its own four corners, inset slightly so the drag handles
 * start visibly inside the photo. Used whenever detection finds nothing to
 * work with — an unremarkable photo, a document that fills the whole frame,
 * or a background too similar to the page — so the user still gets an
 * adjustable quad rather than an error.
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

/**
 * jscanify's detection is just "the largest contour in the image" — on a
 * clean, high-contrast photo that's the document, but on a busy background
 * (or a photo with no visible border around the page) it can just as easily
 * be the outer edge of the picture itself. That failure mode is dangerous
 * precisely because it *looks* like a confident detection — four plausible
 * corners, near the image's own edges — while actually cropping nothing at
 * all, which reads as "the scan feature doesn't do anything."
 *
 * A quad covering almost the entire frame is far more likely to be that
 * misdetection than an intentional edge-to-edge photo of a page, so it's
 * treated as a failed detection instead: the caller falls back to
 * `defaultCorners()`, and the UI tells the user to drag the corners
 * themselves rather than silently "succeeding" with a no-op crop.
 */
const SUSPICIOUS_FULL_FRAME_AREA_RATIO = 0.92;

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
 * @param corners A detected quad, in an image's own pixel coordinates.
 * @param imageWidth The image's width, in pixels.
 * @param imageHeight The image's height, in pixels.
 *
 * @returns Whether `corners` cover so much of the image that it was more
 * likely a misdetection of the frame itself than a real document.
 */
export function isSuspiciouslyFullFrame(
    corners: DocumentCorners,
    imageWidth: number,
    imageHeight: number,
): boolean {
    return (
        quadArea(corners) / (imageWidth * imageHeight) >
        SUSPICIOUS_FULL_FRAME_AREA_RATIO
    );
}

/**
 * Find the largest rectangular contour in an image and return its four
 * corners, or `null` if nothing looked like a document (including a
 * "detection" implausible enough to be the image's own frame — see
 * `isSuspiciouslyFullFrame()`).
 *
 * @param cv The loaded OpenCV.js module (from `loadOpenCv()`).
 * @param image The photo to search.
 *
 * @returns The detected corners, or `null` if no contour was found.
 */
export function detectDocumentCorners(
    cv: OpenCv,
    image: HTMLImageElement,
): DocumentCorners | null {
    const img = cv.imread(image);

    try {
        const contour = getScanner().findPaperContour(img);

        if (!contour) {
            return null;
        }

        const corners = getScanner().getCornerPoints(contour);
        contour.delete();

        const {
            topLeftCorner,
            topRightCorner,
            bottomLeftCorner,
            bottomRightCorner,
        } = corners;

        if (
            !topLeftCorner ||
            !topRightCorner ||
            !bottomLeftCorner ||
            !bottomRightCorner
        ) {
            return null;
        }

        const detected = {
            topLeft: topLeftCorner,
            topRight: topRightCorner,
            bottomLeft: bottomLeftCorner,
            bottomRight: bottomRightCorner,
        };

        if (
            isSuspiciouslyFullFrame(
                detected,
                image.naturalWidth,
                image.naturalHeight,
            )
        ) {
            return null;
        }

        return detected;
    } finally {
        img.delete();
    }
}

/**
 * Straighten an image into a rectangle, mapping the given corners (in the
 * source image's own pixel coordinates) onto the output's four corners.
 * `loadOpenCv()` must already have resolved before calling this — that's
 * what wires up the `window.cv` jscanify reads.
 *
 * @param image The photo to straighten.
 * @param corners The document's corners within `image`.
 * @param outputWidth Desired output width, in pixels.
 * @param outputHeight Desired output height, in pixels.
 *
 * @returns A canvas containing the straightened image.
 */
export function warpToCorners(
    image: HTMLImageElement,
    corners: DocumentCorners,
    outputWidth: number,
    outputHeight: number,
): HTMLCanvasElement {
    const canvas = getScanner().extractPaper(image, outputWidth, outputHeight, {
        topLeftCorner: corners.topLeft,
        topRightCorner: corners.topRight,
        bottomLeftCorner: corners.bottomLeft,
        bottomRightCorner: corners.bottomRight,
    });

    if (!canvas) {
        throw new Error('jscanify failed to extract the paper from the image');
    }

    return canvas;
}

/**
 * Convert a canvas to a `File`, for handing a warped scan back into the same
 * upload path a raw camera photo already goes through.
 *
 * @param canvas The canvas to encode.
 * @param filename The filename to give the resulting file.
 *
 * @returns The encoded file, or `null` if the canvas failed to encode (a
 * zero-size canvas, or a browser that refuses `toBlob` for it).
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
