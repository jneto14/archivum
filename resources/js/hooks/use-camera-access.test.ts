import { renderHook } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { useCameraAccess } from '@/hooks/use-camera-access';

/**
 * The reason this exists: `getUserMedia` being present was read as "there is a
 * camera worth aiming at a page", and every desktop browser over HTTPS answers
 * yes to that. A PC was offered the viewfinder instead of the QR pairing.
 *
 * Each of the four answers means something different to whoever pressed the
 * button, so each is pinned rather than only the happy one.
 */
type Environment = {
    getUserMedia: boolean;
    secure: boolean;
    coarsePointer: boolean;
};

/**
 * Stubbed one property at a time rather than by replacing `window` and
 * `navigator` wholesale, which would take the DOM the hook renders into with
 * them.
 */
function stub({ getUserMedia, secure, coarsePointer }: Environment): void {
    vi.stubGlobal('isSecureContext', secure);
    vi.stubGlobal('matchMedia', (query: string) => ({
        matches: query === '(pointer: coarse)' && coarsePointer,
    }));

    Object.defineProperty(navigator, 'mediaDevices', {
        value: getUserMedia ? { getUserMedia: () => {} } : undefined,
        configurable: true,
    });
}

afterEach(() => {
    vi.unstubAllGlobals();
});

it('opens the camera on a handheld device', () => {
    stub({ getUserMedia: true, secure: true, coarsePointer: true });

    expect(renderHook(() => useCameraAccess()).result.current).toBe(
        'available',
    );
});

/**
 * The defect. A desktop has the API and often a webcam bolted to a monitor,
 * neither of which makes it something you can hold over a sheet of paper.
 */
it('does not offer the camera on a desktop that has one', () => {
    stub({ getUserMedia: true, secure: true, coarsePointer: false });

    expect(renderHook(() => useCameraAccess()).result.current).toBe(
        'not-handheld',
    );
});

/**
 * Told apart from having no camera, because what to do about it is different:
 * this one is the deployment rather than the device.
 */
it('says when the page is not in a secure context', () => {
    stub({ getUserMedia: false, secure: false, coarsePointer: true });

    expect(renderHook(() => useCameraAccess()).result.current).toBe('insecure');
});

it('says when a secure page is still offered no camera', () => {
    stub({ getUserMedia: false, secure: true, coarsePointer: true });

    expect(renderHook(() => useCameraAccess()).result.current).toBe(
        'unavailable',
    );
});
