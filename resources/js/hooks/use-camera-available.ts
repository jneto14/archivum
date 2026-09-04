import { useSyncExternalStore } from 'react';

/** Nothing to subscribe to: whether there is a camera API does not change. */
const neverChanges = () => () => {};

/**
 * Whether this device can be asked for a live camera at all.
 *
 * Mostly a question about the connection rather than the hardware:
 * `mediaDevices` is undefined outside a secure context, so a phone reached over
 * plain HTTP on a LAN address reports no camera however many it has. That is
 * also the honest answer — there is no camera this page can open.
 *
 * Read through `useSyncExternalStore` so the server, which has no `navigator`,
 * gets a defined answer of its own instead of the first client render
 * disagreeing with the markup it is hydrating.
 *
 * @return Whether `getUserMedia` can be called; always false on the server.
 */
export function useCameraAvailable(): boolean {
    return useSyncExternalStore(
        neverChanges,
        () => typeof navigator.mediaDevices?.getUserMedia === 'function',
        () => false,
    );
}
