import { describe, expect, it } from 'vitest';
import { defaultCorners } from '@/lib/document-scan';

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
