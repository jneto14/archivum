import { renderHook } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { useCameraAccess } from '@/hooks/use-camera-access';

function stubEnvironment(
    mediaDevices: object | undefined,
    isSecureContext: boolean,
) {
    Object.defineProperty(navigator, 'mediaDevices', {
        value: mediaDevices,
        configurable: true,
    });
    Object.defineProperty(window, 'isSecureContext', {
        value: isSecureContext,
        configurable: true,
    });
}

afterEach(() => stubEnvironment(undefined, false));

describe('useCameraAccess', () => {
    it('reports a camera when there is one to open', () => {
        stubEnvironment({ getUserMedia: () => {} }, true);

        expect(renderHook(() => useCameraAccess()).result.current).toBe(
            'available',
        );
    });

    // The case that reads as the scanner being broken: a phone with three
    // cameras, reached over plain HTTP, where the API is simply not there.
    // Saying which of the two it is turns a dead end into an instruction.
    it('blames the connection when there is no camera API outside a secure context', () => {
        stubEnvironment(undefined, false);

        expect(renderHook(() => useCameraAccess()).result.current).toBe(
            'insecure',
        );
    });

    it('blames the device when the connection is secure and there is still none', () => {
        stubEnvironment(undefined, true);

        expect(renderHook(() => useCameraAccess()).result.current).toBe(
            'unavailable',
        );
    });

    // A secure context with the object present but nothing callable on it —
    // reading the property alone would call this a camera.
    it('is not fooled by a mediaDevices without getUserMedia on it', () => {
        stubEnvironment({}, true);

        expect(renderHook(() => useCameraAccess()).result.current).toBe(
            'unavailable',
        );
    });
});
