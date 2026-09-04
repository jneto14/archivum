/**
 * The OpenCV.js half of the scan pipeline, split out so `document-scan.ts`
 * can pull it in with a dynamic `import()` and keep ~13MB out of the initial
 * page load.
 *
 * The split is what makes that import safe: `import()`ing OpenCV.js directly
 * throws, while `import()`ing this module hands back an ordinary namespace.
 * See .ai/rules/lib.md before changing how OpenCV.js is imported or fed.
 */
import * as openCvNamespace from '@techstark/opencv-js';
import JScanify from 'jscanify/client';
import { isImplausibleDocument } from '@/lib/document-scan';
import type { DocumentCorners, Scanner } from '@/lib/document-scan';

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

/**
 * Wait for OpenCV.js's WASM runtime and publish it as the global `cv`
 * jscanify reads, then hand back the operations that need it.
 *
 * @returns A scanner backed by an initialized OpenCV.js.
 *
 * @throws If OpenCV.js never resolves to something with its API on it.
 */
export async function createScanner(): Promise<Scanner> {
    const cv = await initializeOpenCv();
    globalThis.cv = cv as unknown as typeof globalThis.cv;
    const scanner = new JScanify();

    return {
        detectCorners: (image) => detectCorners(cv, scanner, image),
        warp: (image, corners, outputWidth, outputHeight) =>
            warp(scanner, image, corners, outputWidth, outputHeight),
    };
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
 * Find the document in a photo.
 *
 * @param cv The initialized OpenCV.js module.
 * @param scanner The jscanify instance to detect with.
 * @param image The photo to search.
 *
 * @returns The document's four corners in `image`'s own pixel coordinates, or
 * `null` if nothing convincing was found — including a "detection" that covers
 * the whole frame, or one small enough to be something printed on the page.
 */
function detectCorners(
    cv: OpenCv,
    scanner: JScanify,
    image: HTMLImageElement,
): DocumentCorners | null {
    const img = cv.imread(toFullSizeCanvas(image));

    try {
        const contour = scanner.findPaperContour(img);

        if (!contour) {
            return null;
        }

        const corners = scanner.getCornerPoints(contour);
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

        return isImplausibleDocument(
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
 *
 * @param scanner The jscanify instance to warp with.
 * @param image The photo to straighten.
 * @param corners The document's corners within `image`, in its own pixel coordinates.
 * @param outputWidth Desired output width, in pixels.
 * @param outputHeight Desired output height, in pixels.
 *
 * @returns A canvas containing the straightened image.
 *
 * @throws If jscanify could not produce a result.
 */
function warp(
    scanner: JScanify,
    image: HTMLImageElement,
    corners: DocumentCorners,
    outputWidth: number,
    outputHeight: number,
): HTMLCanvasElement {
    const canvas = scanner.extractPaper(
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
