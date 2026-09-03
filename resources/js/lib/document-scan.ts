/**
 * Detect a document in a photo and straighten it, for the phone capture page
 * (ARC-105). Detection and warping are jscanify's
 * (https://github.com/ColonelParrot/jscanify, MIT License) — `jscanify/client`
 * rather than the package default, which is a Node build.
 *
 * Read .ai/rules/lib.md before touching how OpenCV.js is imported or fed:
 * both the static import and `toFullSizeCanvas()` look redundant and are not.
 */
import * as openCvNamespace from '@techstark/opencv-js';
import JScanify from 'jscanify/client';

export type Point = { x: number; y: number };

export type DocumentCorners = {
    topLeft: Point;
    topRight: Point;
    bottomLeft: Point;
    bottomRight: Point;
};

type Mat = { delete(): void };

/** The slice of OpenCV.js this module calls; jscanify calls the rest itself. */
type OpenCv = {
    Mat: new () => Mat;
    imread(source: HTMLCanvasElement | HTMLImageElement): Mat;
};

/** OpenCV.js before initialization finishes: a promise of itself. */
type OpenCvExport = Partial<OpenCv> & {
    then?: (
        onFulfilled: (value: OpenCv) => void,
        onRejected: (reason: unknown) => void,
    ) => void;
};

let openCvPromise: Promise<OpenCv> | null = null;

/**
 * Wait for OpenCV.js's WASM runtime, once, and publish it as the global `cv`
 * jscanify reads. Failures aren't cached, so a later photo can try again.
 *
 * @returns The ready-to-use OpenCV.js module.
 *
 * @throws If the module never resolves to something with OpenCV's API on it.
 */
export function loadOpenCv(): Promise<OpenCv> {
    openCvPromise ??= initializeOpenCv()
        .then((cv) => {
            globalThis.cv = cv as unknown as typeof globalThis.cv;

            return cv;
        })
        .catch((error: unknown) => {
            openCvPromise = null;

            throw error;
        });

    return openCvPromise;
}

/** @returns OpenCV.js once its runtime is ready to be called. */
function initializeOpenCv(): Promise<OpenCv> {
    const exported = ((openCvNamespace as { default?: unknown }).default ??
        openCvNamespace) as OpenCvExport;

    // `imread`, not `Mat`: `Mat` is a stub present before the runtime is.
    if (typeof exported.imread === 'function') {
        return Promise.resolve(exported as OpenCv);
    }

    if (typeof exported.then === 'function') {
        return new Promise<OpenCv>((resolve, reject) =>
            exported.then!(resolve, reject),
        );
    }

    return Promise.reject(new Error('OpenCV.js exported no usable module'));
}

let scanner: JScanify | null = null;

/** @returns A shared jscanify instance — it holds no state beyond the global `cv`, so one is enough for the page's lifetime. */
function getScanner(): JScanify {
    scanner ??= new JScanify();

    return scanner;
}

/**
 * OpenCV.js reads an `<img>` at its layout size, so it must be handed a
 * canvas instead to get coordinates in the photo's real pixels.
 *
 * @param image The photo to copy.
 *
 * @returns A canvas holding `image` at full resolution.
 */
function toFullSizeCanvas(image: HTMLImageElement): HTMLCanvasElement {
    const canvas = document.createElement('canvas');
    canvas.width = image.naturalWidth;
    canvas.height = image.naturalHeight;
    canvas.getContext('2d')?.drawImage(image, 0, 0);

    return canvas;
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
 * @param corners A detected quad, in the image's own pixel coordinates.
 * @param imageWidth The image's width, in pixels.
 * @param imageHeight The image's height, in pixels.
 *
 * @returns Whether `corners` cover so much of the image that they are more
 * likely the frame than a document within it.
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
 * Find the document in a photo.
 *
 * @param cv The initialized OpenCV.js module, from `loadOpenCv()`.
 * @param image The photo to search.
 *
 * @returns The document's four corners in `image`'s own pixel coordinates,
 * or `null` if nothing convincing was found — including a "detection" that
 * covers the frame (see `isSuspiciouslyFullFrame()`).
 */
export function detectDocumentCorners(
    cv: OpenCv,
    image: HTMLImageElement,
): DocumentCorners | null {
    const img = cv.imread(toFullSizeCanvas(image));

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

        return isSuspiciouslyFullFrame(
            detected,
            image.naturalWidth,
            image.naturalHeight,
        )
            ? null
            : detected;
    } finally {
        img.delete();
    }
}

/**
 * Straighten an image, mapping `corners` onto the output's own four corners.
 * `loadOpenCv()` must already have resolved.
 *
 * @param image The photo to straighten.
 * @param corners The document's corners within `image`, in its own pixel coordinates.
 * @param outputWidth Desired output width, in pixels.
 * @param outputHeight Desired output height, in pixels.
 *
 * @returns A canvas containing the straightened image.
 *
 * @throws If jscanify could not produce a result.
 */
export function warpToCorners(
    image: HTMLImageElement,
    corners: DocumentCorners,
    outputWidth: number,
    outputHeight: number,
): HTMLCanvasElement {
    const canvas = getScanner().extractPaper(
        toFullSizeCanvas(image),
        outputWidth,
        outputHeight,
        {
            topLeftCorner: corners.topLeft,
            topRightCorner: corners.topRight,
            bottomLeftCorner: corners.bottomLeft,
            bottomRightCorner: corners.bottomRight,
        },
    );

    if (!canvas) {
        throw new Error('jscanify failed to extract the paper from the image');
    }

    return canvas;
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
