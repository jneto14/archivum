import { describe, expect, it } from 'vitest';
import {
    VIEWFINDER_MIN_AREA_RATIO,
    cornersToPolygon,
    defaultCorners,
    isConvexQuad,
    isDifferentSubject,
    isImplausibleDocument,
    orderCorners,
    scaleCorners,
    smoothCorners,
} from '@/lib/document-scan';
import type { DocumentCorners, Point } from '@/lib/document-scan';

describe('defaultCorners', () => {
    it('insets each corner from the image edge rather than sitting flush on it', () => {
        const corners = defaultCorners(1000, 500);

        expect(corners.topLeft).toEqual({ x: 80, y: 40 });
        expect(corners.topRight).toEqual({ x: 920, y: 40 });
        expect(corners.bottomLeft).toEqual({ x: 80, y: 460 });
        expect(corners.bottomRight).toEqual({ x: 920, y: 460 });
    });

    it('keeps the inset proportional, so a small image is not swallowed by it', () => {
        const corners = defaultCorners(100, 100);

        expect(corners.topLeft.x).toBeGreaterThan(0);
        expect(corners.topLeft.x).toBeLessThan(corners.topRight.x);
        expect(corners.topLeft.y).toBeLessThan(corners.bottomLeft.y);
    });
});

describe('isImplausibleDocument', () => {
    /** A quad of the given size, centred in a 1000x800 photo. */
    function centred(width: number, height: number): DocumentCorners {
        const left = (1000 - width) / 2;
        const top = (800 - height) / 2;

        return {
            topLeft: { x: left, y: top },
            topRight: { x: left + width, y: top },
            bottomLeft: { x: left, y: top + height },
            bottomRight: { x: left + width, y: top + height },
        };
    }

    it('refuses a quad covering essentially the whole image', () => {
        // jscanify answers "the largest closed shape", which here is the
        // photo's own outer edge rather than the document within it — a miss
        // that looks confident, four clean corners and all, while cropping
        // nothing at all.
        expect(isImplausibleDocument(centred(1000, 800), 1000, 800)).toBe(true);
    });

    it('refuses a quad small enough to be something printed on the page', () => {
        expect(isImplausibleDocument(centred(300, 200), 1000, 800)).toBe(true);
    });

    it('accepts a document that legitimately fills most of the frame', () => {
        expect(isImplausibleDocument(centred(940, 740), 1000, 800)).toBe(false);
    });

    it('accepts the inset default, so the fallback is never refused in turn', () => {
        expect(
            isImplausibleDocument(defaultCorners(1000, 800), 1000, 800),
        ).toBe(false);
    });

    it('accepts a page photographed with room around it', () => {
        expect(isImplausibleDocument(centred(700, 570), 1000, 800)).toBe(false);
    });
});

describe('scaleCorners', () => {
    const corners: DocumentCorners = {
        topLeft: { x: 10, y: 20 },
        topRight: { x: 90, y: 20 },
        bottomLeft: { x: 10, y: 80 },
        bottomRight: { x: 90, y: 80 },
    };

    it('carries a quad from the detection frame up to the full-size one', () => {
        const scaled = scaleCorners(
            corners,
            { width: 100, height: 100 },
            { width: 400, height: 400 },
        );

        expect(scaled.topLeft).toEqual({ x: 40, y: 80 });
        expect(scaled.bottomRight).toEqual({ x: 360, y: 320 });
    });

    it('scales each axis on its own, since the two frames may not share a ratio', () => {
        const scaled = scaleCorners(
            corners,
            { width: 100, height: 100 },
            { width: 200, height: 400 },
        );

        expect(scaled.topRight).toEqual({ x: 180, y: 80 });
    });

    it('leaves a quad alone when the two frames are the same size', () => {
        expect(
            scaleCorners(
                corners,
                { width: 100, height: 100 },
                { width: 100, height: 100 },
            ),
        ).toEqual(corners);
    });

    // A video element reports 0x0 until it has a frame; dividing by that
    // would put every corner at NaN.
    it('survives a source frame that has no size yet', () => {
        const scaled = scaleCorners(
            corners,
            { width: 0, height: 0 },
            { width: 400, height: 400 },
        );

        expect(scaled.topLeft).toEqual({ x: 0, y: 0 });
    });
});

describe('cornersToPolygon', () => {
    it('walks the perimeter rather than reading order, or the quad is a bowtie', () => {
        expect(
            cornersToPolygon({
                topLeft: { x: 0, y: 0 },
                topRight: { x: 10, y: 0 },
                bottomLeft: { x: 0, y: 5 },
                bottomRight: { x: 10, y: 5 },
            }),
        ).toBe('0,0 10,0 10,5 0,5');
    });
});

describe('orderCorners', () => {
    /** The corners of a square of `size` centred in a 1000x800 frame and turned `degrees`. */
    function rotatedSquare(size: number, degrees: number): Point[] {
        const radians = (degrees * Math.PI) / 180;
        const half = size / 2;

        return [
            { x: -half, y: -half },
            { x: half, y: -half },
            { x: half, y: half },
            { x: -half, y: half },
        ].map(({ x, y }) => ({
            x: 500 + x * Math.cos(radians) - y * Math.sin(radians),
            y: 400 + x * Math.sin(radians) + y * Math.cos(radians),
        }));
    }

    it('names four scrambled points by where they sit', () => {
        const ordered = orderCorners([
            { x: 90, y: 80 },
            { x: 10, y: 20 },
            { x: 10, y: 80 },
            { x: 90, y: 20 },
        ]);

        expect(ordered).toEqual({
            topLeft: { x: 10, y: 20 },
            topRight: { x: 90, y: 20 },
            bottomRight: { x: 90, y: 80 },
            bottomLeft: { x: 10, y: 80 },
        });
    });

    /** `points` rotated so they no longer arrive in perimeter order. */
    function scrambled(points: Point[]): Point[] {
        return [points[2], points[0], points[3], points[1]];
    }

    it.each([0, 15, 30, 45, 60, 75, 89])(
        'walks the perimeter of a page turned %i degrees, in whatever order the points arrive',
        (degrees) => {
            const ordered = orderCorners(
                scrambled(rotatedSquare(400, degrees)),
            );

            expect(ordered).not.toBeNull();
            expect(isConvexQuad(ordered!)).toBe(true);
        },
    );

    it('walks the perimeter even when the points arrive counter-clockwise', () => {
        const ordered = orderCorners([...rotatedSquare(400, 20)].reverse());

        expect(isConvexQuad(ordered!)).toBe(true);
    });

    it('returns the points it was given, naming them rather than moving them', () => {
        const points = rotatedSquare(400, 30);
        const ordered = orderCorners(scrambled(points));

        expect(new Set(Object.values(ordered!))).toEqual(new Set(points));
    });

    it('calls the corner nearest the image origin the top-left', () => {
        const points = rotatedSquare(400, 30);
        const ordered = orderCorners(scrambled(points))!;
        const nearestOrigin = points.reduce((nearest, point) =>
            point.x + point.y < nearest.x + nearest.y ? point : nearest,
        );

        expect(ordered.topLeft).toEqual(nearestOrigin);
    });

    it('refuses anything that is not exactly four points', () => {
        expect(orderCorners([])).toBeNull();
        expect(
            orderCorners([
                { x: 0, y: 0 },
                { x: 1, y: 0 },
                { x: 1, y: 1 },
            ]),
        ).toBeNull();
        expect(
            orderCorners([
                { x: 0, y: 0 },
                { x: 1, y: 0 },
                { x: 1, y: 1 },
                { x: 0, y: 1 },
                { x: 0.5, y: 1.5 },
            ]),
        ).toBeNull();
    });
});

describe('isConvexQuad', () => {
    it('accepts a rectangle', () => {
        expect(
            isConvexQuad({
                topLeft: { x: 0, y: 0 },
                topRight: { x: 100, y: 0 },
                bottomRight: { x: 100, y: 60 },
                bottomLeft: { x: 0, y: 60 },
            }),
        ).toBe(true);
    });

    it('accepts a page seen from an angle, which is the normal case', () => {
        expect(
            isConvexQuad({
                topLeft: { x: 20, y: 8 },
                topRight: { x: 96, y: 0 },
                bottomRight: { x: 110, y: 58 },
                bottomLeft: { x: 4, y: 70 },
            }),
        ).toBe(true);
    });

    it('rejects a bowtie, which is what four good points in the wrong order are', () => {
        expect(
            isConvexQuad({
                topLeft: { x: 0, y: 0 },
                topRight: { x: 100, y: 0 },
                bottomRight: { x: 0, y: 60 },
                bottomLeft: { x: 100, y: 60 },
            }),
        ).toBe(false);
    });

    it('rejects a concave quad, the shape a thumb or a folded corner makes', () => {
        expect(
            isConvexQuad({
                topLeft: { x: 0, y: 0 },
                topRight: { x: 100, y: 0 },
                bottomRight: { x: 50, y: 30 },
                bottomLeft: { x: 0, y: 60 },
            }),
        ).toBe(false);
    });

    it('rejects a quad with three points on a line, which is a triangle', () => {
        expect(
            isConvexQuad({
                topLeft: { x: 0, y: 0 },
                topRight: { x: 50, y: 0 },
                bottomRight: { x: 100, y: 0 },
                bottomLeft: { x: 0, y: 60 },
            }),
        ).toBe(false);
    });
});

describe('isImplausibleDocument, viewfinder and shape', () => {
    /** A quad of the given size, centred in a 1000x800 frame. */
    function centred(width: number, height: number): DocumentCorners {
        const left = (1000 - width) / 2;
        const top = (800 - height) / 2;

        return {
            topLeft: { x: left, y: top },
            topRight: { x: left + width, y: top },
            bottomLeft: { x: left, y: top + height },
            bottomRight: { x: left + width, y: top + height },
        };
    }

    it('accepts a page still being approached, which a framed photo would refuse', () => {
        const approaching = centred(420, 340);

        expect(isImplausibleDocument(approaching, 1000, 800)).toBe(true);
        expect(
            isImplausibleDocument(
                approaching,
                1000,
                800,
                VIEWFINDER_MIN_AREA_RATIO,
            ),
        ).toBe(false);
    });

    it('still refuses a full-frame quad however low the floor is put', () => {
        expect(
            isImplausibleDocument(
                centred(1000, 800),
                1000,
                800,
                VIEWFINDER_MIN_AREA_RATIO,
            ),
        ).toBe(true);
    });

    it('refuses a band, which clears the area floor but is not a sheet of paper', () => {
        const band = centred(950, 110);

        expect(
            isImplausibleDocument(band, 1000, 800, VIEWFINDER_MIN_AREA_RATIO),
        ).toBe(true);
    });

    it('accepts a page that is merely narrow, not a band', () => {
        expect(
            isImplausibleDocument(
                centred(950, 260),
                1000,
                800,
                VIEWFINDER_MIN_AREA_RATIO,
            ),
        ).toBe(false);
    });

    it('accepts a page whose proportions are merely stretched by perspective', () => {
        expect(isImplausibleDocument(centred(900, 500), 1000, 800)).toBe(false);
    });
});

describe('smoothCorners', () => {
    const previous: DocumentCorners = {
        topLeft: { x: 0, y: 0 },
        topRight: { x: 100, y: 0 },
        bottomLeft: { x: 0, y: 100 },
        bottomRight: { x: 100, y: 100 },
    };
    const next: DocumentCorners = {
        topLeft: { x: 10, y: 20 },
        topRight: { x: 110, y: 20 },
        bottomLeft: { x: 10, y: 120 },
        bottomRight: { x: 110, y: 120 },
    };

    it('moves the outline part of the way, so per-frame jitter is absorbed', () => {
        expect(smoothCorners(previous, next, 0.5)).toEqual({
            topLeft: { x: 5, y: 10 },
            topRight: { x: 105, y: 10 },
            bottomLeft: { x: 5, y: 110 },
            bottomRight: { x: 105, y: 110 },
        });
    });

    it('lands exactly on the detection at full weight', () => {
        expect(smoothCorners(previous, next, 1)).toEqual(next);
    });

    it('leaves the outline where it was at zero weight', () => {
        expect(smoothCorners(previous, next, 0)).toEqual(previous);
    });
});

describe('isDifferentSubject', () => {
    const previous: DocumentCorners = {
        topLeft: { x: 100, y: 100 },
        topRight: { x: 900, y: 100 },
        bottomLeft: { x: 100, y: 700 },
        bottomRight: { x: 900, y: 700 },
    };

    it('treats a hand-held drift as the same page, so it gets smoothed', () => {
        const drifted = smoothCorners(previous, previous, 1);
        drifted.topLeft = { x: 112, y: 108 };

        expect(isDifferentSubject(drifted, previous, 1000, 800)).toBe(false);
    });

    it('treats a corner jumping across the frame as a new subject', () => {
        expect(
            isDifferentSubject(
                previous,
                { ...previous, topLeft: { x: 600, y: 500 } },
                1000,
                800,
            ),
        ).toBe(true);
    });

    it('calls it a new subject when the frame has no size to measure against', () => {
        expect(isDifferentSubject(previous, previous, 0, 0)).toBe(true);
    });
});
