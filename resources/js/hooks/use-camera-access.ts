import { useSyncExternalStore } from 'react';

/**
 * Whether a live camera can be opened here, and when it cannot, why not.
 *
 * The reason matters as much as the answer. Without it, pressing "scan" on a
 * phone that ought to have a camera silently produces the pair-another-device
 * dialog instead, with nothing anywhere saying what the browser decided — which
 * reads as the scanner being broken rather than as the page not being allowed
 * to open a camera.
 */
export type CameraAccess =
    /** `getUserMedia` is there to be called. */
    | 'available'
    /** No camera API, and the page is not in a secure context — that is why. */
    | 'insecure'
    /** Secure, but the browser still offers no camera at all. */
    | 'unavailable';

/** Nothing to subscribe to: none of this changes within a page's life. */
const neverChanges = () => () => {};

function readCameraAccess(): CameraAccess {
    if (typeof navigator.mediaDevices?.getUserMedia === 'function') {
        return 'available';
    }

    // `mediaDevices` is undefined outside a secure context however many cameras
    // the device has, so an installation reached over plain HTTP on a LAN
    // address has none as far as any page on it is concerned.
    return window.isSecureContext ? 'unavailable' : 'insecure';
}

/**
 * Read through `useSyncExternalStore` so the server, which has neither
 * `navigator` nor `window`, gets a defined answer of its own rather than the
 * first client render disagreeing with the markup it is hydrating.
 *
 * @return What this device can do about a camera; always 'unavailable' on the server.
 */
export function useCameraAccess(): CameraAccess {
    return useSyncExternalStore(
        neverChanges,
        readCameraAccess,
        () => 'unavailable' as const,
    );
}
