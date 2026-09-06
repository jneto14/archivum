/**
 * jscanify ships no type declarations of its own, and its default export
 * (`jscanify`) resolves to a Node build we never import — only the
 * `jscanify/client` subpath, a plain browser build. This covers just that
 * subpath, and just the method `@/lib/document-scan` actually calls — since
 * ARC-117 that is the perspective warp alone, detection being our own.
 */
declare module 'jscanify/client' {
    type Point = { x: number; y: number };

    export default class JScanify {
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
