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
import {
    intrinsicSize,
    isConvexQuad,
    isImplausibleDocument,
    orderCorners,
} from '@/lib/document-scan';
import type {
    DocumentCorners,
    Point,
    ScanImage,
    Scanner,
} from '@/lib/document-scan';

type Mat = {
    delete(): void;
    rows: number;
    data32S: Int32Array;
};

type MatVector = {
    size(): number;
    /** @returns A handle of its own, which the caller must delete. */
    get(index: number): Mat;
    delete(): void;
};

/** An OpenCV.js value object — a `Size`, a `Point`, a `Scalar`. */
type CvStruct = object;

/** The slice of OpenCV.js this module calls; jscanify calls the rest itself. */
type OpenCv = {
    Mat: new () => Mat;
    MatVector: new () => MatVector;
    Size: new (width: number, height: number) => CvStruct;
    Point: new (x: number, y: number) => CvStruct;
    imread(source: HTMLCanvasElement | HTMLImageElement): Mat;
    cvtColor(
        source: Mat,
        destination: Mat,
        code: number,
        channels: number,
    ): void;
    GaussianBlur(
        source: Mat,
        destination: Mat,
        kernelSize: CvStruct,
        sigmaX: number,
        sigmaY: number,
        borderType: number,
    ): void;
    Canny(
        source: Mat,
        edges: Mat,
        lowThreshold: number,
        highThreshold: number,
    ): void;
    getStructuringElement(shape: number, kernelSize: CvStruct): Mat;
    dilate(
        source: Mat,
        destination: Mat,
        kernel: Mat,
        anchor: CvStruct,
        iterations: number,
        borderType: number,
        borderValue: CvStruct,
    ): void;
    morphologyDefaultBorderValue(): CvStruct;
    findContours(
        image: Mat,
        contours: MatVector,
        hierarchy: Mat,
        mode: number,
        method: number,
    ): void;
    contourArea(contour: Mat, oriented: boolean): number;
    arcLength(curve: Mat, closed: boolean): number;
    approxPolyDP(
        curve: Mat,
        approximation: Mat,
        epsilon: number,
        closed: boolean,
    ): void;
    COLOR_RGBA2GRAY: number;
    BORDER_DEFAULT: number;
    BORDER_CONSTANT: number;
    MORPH_RECT: number;
    RETR_LIST: number;
    CHAIN_APPROX_SIMPLE: number;
};

/** Side of the Gaussian kernel that denoises the frame before edges are taken. */
const BLUR_KERNEL_SIZE = 5;

/** Canny's hysteresis thresholds. The wide gap keeps a faint paper edge that touches a strong one. */
const CANNY_LOW_THRESHOLD = 75;
const CANNY_HIGH_THRESHOLD = 200;

/**
 * How far a polygon may sit from the contour it approximates, as a share of
 * that contour's perimeter.
 *
 * The usual 2%: tight enough that a rounded corner or a bowed edge is not
 * flattened into a straight one, loose enough that the ripples a Canny edge
 * has along a real paper boundary collapse to four sides rather than forty.
 */
const APPROXIMATION_EPSILON_RATIO = 0.02;

/**
 * Contours examined, largest first, before giving up.
 *
 * The page is not reliably the biggest thing found — a desk edge or the frame
 * border outranks it — but it is reliably among the first few, and every
 * candidate past that is small enough to be refused on area anyway.
 */
const CANDIDATE_CONTOURS = 8;

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
        detectCorners: (image, minAreaRatio) =>
            detectCorners(cv, image, minAreaRatio),
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
 * A canvas is already in its own pixels and is returned untouched — which is
 * what the live viewfinder hands in, one frame at a time.
 *
 * @param image The photo or frame to copy.
 *
 * @returns A canvas holding `image` at full resolution.
 */
function toFullSizeCanvas(image: ScanImage): HTMLCanvasElement {
    if (image instanceof HTMLCanvasElement) {
        return image;
    }

    const canvas = document.createElement('canvas');
    canvas.width = image.naturalWidth;
    canvas.height = image.naturalHeight;
    canvas.getContext('2d')?.drawImage(image, 0, 0);

    return canvas;
}

/**
 * Find the document in an image.
 *
 * Grayscale, blur, Canny, then close the result before tracing it. Each step
 * earns its place against how jscanify does the same thing, which is what
 * ARC-117 replaced:
 *
 * - **Grayscale first.** `imread` hands back four interleaved channels, and an
 *   edge detector run across them answers about colour transitions rather than
 *   about the boundary of a sheet of paper.
 * - **Blur before Canny, not after.** Denoising is what stops paper grain and
 *   sensor noise becoming edges; applied afterwards it only smears the edges
 *   that were already found.
 * - **Dilate.** A page on a desk of similar tone has a boundary that fades in
 *   and out, so its Canny edge arrives in pieces — and a contour that does not
 *   close encloses no area at all, which is precisely how a crisp box printed
 *   on the page comes to outrank the page (ARC-110). Thickening the edges
 *   bridges those gaps.
 *
 * What comes back is then chosen by shape rather than by size alone: the
 * largest few contours are each approximated to a polygon, and the first that
 * is a convex quadrilateral of plausible size and proportion wins.
 *
 * @param cv The initialized OpenCV.js module.
 * @param image The photo or frame to search.
 * @param minAreaRatio Smallest share of the image a detection may cover; defaults to the framed-photo floor.
 *
 * @returns The document's four corners in `image`'s own pixel coordinates, or
 * `null` if nothing convincing was found.
 */
function detectCorners(
    cv: OpenCv,
    image: ScanImage,
    minAreaRatio?: number,
): DocumentCorners | null {
    const { width, height } = intrinsicSize(image);
    const source = cv.imread(toFullSizeCanvas(image));
    const gray = new cv.Mat();
    const blurred = new cv.Mat();
    const edges = new cv.Mat();
    const closed = new cv.Mat();
    const contours = new cv.MatVector();
    const hierarchy = new cv.Mat();
    let kernel: Mat | null = null;

    try {
        cv.cvtColor(source, gray, cv.COLOR_RGBA2GRAY, 0);
        cv.GaussianBlur(
            gray,
            blurred,
            new cv.Size(BLUR_KERNEL_SIZE, BLUR_KERNEL_SIZE),
            0,
            0,
            cv.BORDER_DEFAULT,
        );
        cv.Canny(blurred, edges, CANNY_LOW_THRESHOLD, CANNY_HIGH_THRESHOLD);

        kernel = cv.getStructuringElement(cv.MORPH_RECT, new cv.Size(3, 3));
        cv.dilate(
            edges,
            closed,
            kernel,
            new cv.Point(-1, -1),
            1,
            cv.BORDER_CONSTANT,
            cv.morphologyDefaultBorderValue(),
        );

        cv.findContours(
            closed,
            contours,
            hierarchy,
            cv.RETR_LIST,
            cv.CHAIN_APPROX_SIMPLE,
        );

        return firstPlausibleQuad(cv, contours, width, height, minAreaRatio);
    } finally {
        kernel?.delete();
        hierarchy.delete();
        contours.delete();
        closed.delete();
        edges.delete();
        blurred.delete();
        gray.delete();
        source.delete();
    }
}

/**
 * Pick the document out of the traced contours.
 *
 * Ordered by area and cut off at `CANDIDATE_CONTOURS` rather than searched
 * exhaustively: a frame yields hundreds of contours, this runs several times a
 * second on a phone, and anything outside the largest few is too small to be
 * accepted anyway.
 *
 * @param cv The initialized OpenCV.js module.
 * @param contours Every contour traced in the image.
 * @param width The image's width, in pixels.
 * @param height The image's height, in pixels.
 * @param minAreaRatio Smallest share of the image a detection may cover.
 *
 * @returns The winning quad in the image's own pixels, or `null` if none qualified.
 */
function firstPlausibleQuad(
    cv: OpenCv,
    contours: MatVector,
    width: number,
    height: number,
    minAreaRatio?: number,
): DocumentCorners | null {
    const byArea: { index: number; area: number }[] = [];

    for (let index = 0; index < contours.size(); index++) {
        const contour = contours.get(index);

        byArea.push({ index, area: cv.contourArea(contour, false) });
        contour.delete();
    }

    byArea.sort((first, second) => second.area - first.area);

    for (const { index } of byArea.slice(0, CANDIDATE_CONTOURS)) {
        const contour = contours.get(index);
        const approximation = new cv.Mat();

        try {
            cv.approxPolyDP(
                contour,
                approximation,
                APPROXIMATION_EPSILON_RATIO * cv.arcLength(contour, true),
                true,
            );

            if (approximation.rows !== 4) {
                continue;
            }

            const corners = orderCorners(pointsOf(approximation));

            if (
                corners &&
                isConvexQuad(corners) &&
                !isImplausibleDocument(corners, width, height, minAreaRatio)
            ) {
                return corners;
            }
        } finally {
            approximation.delete();
            contour.delete();
        }
    }

    return null;
}

/**
 * Read a contour's vertices out of the flat buffer OpenCV stores them in.
 *
 * @param contour A contour of `CV_32SC2` points.
 *
 * @returns Its vertices, in the order they are stored.
 */
function pointsOf(contour: Mat): Point[] {
    const coordinates = contour.data32S;
    const points: Point[] = [];

    for (let index = 0; index + 1 < coordinates.length; index += 2) {
        points.push({ x: coordinates[index], y: coordinates[index + 1] });
    }

    return points;
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
    image: ScanImage,
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
