import { describe, expect, it } from 'vitest';
import { defaultCorners, isSuspiciouslyFullFrame } from '@/lib/document-scan';
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

describe('isSuspiciouslyFullFrame', () => {
    const fullFrame: DocumentCorners = {
        topLeft: { x: 0, y: 0 },
        topRight: { x: 1000, y: 0 },
        bottomLeft: { x: 0, y: 800 },
        bottomRight: { x: 1000, y: 800 },
    };

    it('flags a quad covering essentially the whole image', () => {
        // jscanify's "largest contour" heuristic can end up picking the
        // picture's own outer edge instead of the document within it — a
        // misdetection that looks confident (four clean corners) while
        // actually cropping nothing, which is indistinguishable from the
        // scan feature silently doing nothing at all.
        expect(isSuspiciouslyFullFrame(fullFrame, 1000, 800)).toBe(true);
    });

    it('does not flag a quad that leaves a visible margin', () => {
        const corners = defaultCorners(1000, 800);

        expect(isSuspiciouslyFullFrame(corners, 1000, 800)).toBe(false);
    });

    it('does not flag a document that legitimately fills most of the frame', () => {
        const corners: DocumentCorners = {
            topLeft: { x: 30, y: 30 },
            topRight: { x: 970, y: 30 },
            bottomLeft: { x: 30, y: 770 },
            bottomRight: { x: 970, y: 770 },
        };

        expect(isSuspiciouslyFullFrame(corners, 1000, 800)).toBe(false);
    });
});
