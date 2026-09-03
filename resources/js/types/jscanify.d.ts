/**
 * jscanify ships no type declarations of its own, and its default export
 * (`jscanify`) resolves to a Node build we never import — only the
 * `jscanify/client` subpath, a plain browser build. This covers just that
 * subpath, and just the methods `@/lib/document-scan` actually calls.
 */
declare module 'jscanify/client' {
    type Point = { x: number; y: number };

    type Mat = { delete(): void };

    type CornerPoints = {
        topLeftCorner?: Point;
        topRightCorner?: Point;
        bottomLeftCorner?: Point;
        bottomRightCorner?: Point;
    };

    export default class JScanify {
        /** @returns The largest contour found in `image`, or `null` if none was. */
        findPaperContour(image: Mat): Mat | null;

        /** @returns The four corners of `contour`, by quadrant — any of which may be missing if that quadrant had no points. */
        getCornerPoints(contour: Mat): CornerPoints;

        /** @returns A canvas with `image`'s detected paper outlined. */
        highlightPaper(
            image: HTMLCanvasElement | HTMLImageElement,
            options?: { color?: string; thickness?: number },
        ): HTMLCanvasElement;

        /** @returns A canvas containing `image` warped so `cornerPoints` (or, absent those, the auto-detected paper) fills a `resultWidth`×`resultHeight` rectangle — or `null` if no corners were given and none could be detected. */
        extractPaper(
            image: HTMLCanvasElement | HTMLImageElement,
            resultWidth: number,
            resultHeight: number,
            cornerPoints?: {
                topLeftCorner: Point;
                topRightCorner: Point;
                bottomLeftCorner: Point;
                bottomRightCorner: Point;
            },
        ): HTMLCanvasElement | null;
    }
}
