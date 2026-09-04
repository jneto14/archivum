import { describe, expect, it } from 'vitest';
import { defaultCorners, isImplausibleDocument } from '@/lib/document-scan';
import type { DocumentCorners } from '@/lib/document-scan';

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

        // 8% of 100 is 8 either side — comfortably inside a tiny image
        // rather than the corners colliding or crossing.
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
        // The other direction, and the one that reached a user: an invoice
        // with a bordered totals box in the middle of it. The box has crisper
        // edges than a sheet of paper on a desk, so it wins on area and the
        // page gets filed as that box (ARC-110).
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
        // Half the frame: further away than anyone normally holds a phone, and
        // still a page rather than a detail on one.
        expect(isImplausibleDocument(centred(700, 570), 1000, 800)).toBe(false);
    });
});
