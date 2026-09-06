import { act, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { DocumentCameraDialog } from '@/components/document-camera-dialog';
import en from '@/lib/translations/en';

const page = vi.hoisted(() => ({ props: { locale: 'en' } }));
vi.mock('@inertiajs/react', () => ({ usePage: () => page }));

// OpenCV.js does not run under jsdom, and the outline is not what these
// assertions are about — only that the camera is opened and let go of.
const documentScan = vi.hoisted(() => ({
    VIEWFINDER_DETECTION_WIDTH: 480,
    VIEWFINDER_MIN_AREA_RATIO: 0.1,
    VIEWFINDER_MISS_TOLERANCE: 3,
    VIEWFINDER_SMOOTHING: 0.5,
    loadScanner: vi.fn(),
    canvasToFile: vi.fn(),
    cornersToPolygon: vi.fn(),
    scaleCorners: vi.fn(),
    smoothCorners: vi.fn(),
    isDifferentSubject: vi.fn(),
}));
vi.mock('@/lib/document-scan', () => documentScan);
vi.mock('@/components/document-scan-review', () => ({
    DocumentScanReview: () => null,
}));

const stop = vi.fn();
const getUserMedia = vi.fn();

function stubStream(): MediaStream {
    return { getTracks: () => [{ stop }] } as unknown as MediaStream;
}

function renderDialog(open: boolean) {
    return render(
        <DocumentCameraDialog
            open={open}
            onOpenChange={vi.fn()}
            onCaptured={vi.fn()}
            onUseAnotherDevice={vi.fn()}
        />,
    );
}

beforeEach(() => {
    vi.clearAllMocks();
    // Never resolves: detection would otherwise loop for the whole test run.
    documentScan.loadScanner.mockReturnValue(new Promise(() => {}));
    getUserMedia.mockResolvedValue(stubStream());
    Object.defineProperty(navigator, 'mediaDevices', {
        value: { getUserMedia },
        configurable: true,
    });
});

it('asks for the back camera, which is the one pointed at the paper', async () => {
    renderDialog(true);

    await waitFor(() => expect(getUserMedia).toHaveBeenCalled());

    expect(getUserMedia.mock.calls[0][0]).toMatchObject({
        video: { facingMode: { ideal: 'environment' } },
    });
});

// A stream nobody stops keeps the camera — and the recording indicator beside
// it — running for as long as the page is open, long after the dialog is gone.
it('releases the camera when the dialog closes', async () => {
    const { rerender } = renderDialog(true);

    await waitFor(() => expect(getUserMedia).toHaveBeenCalled());

    rerender(
        <DocumentCameraDialog
            open={false}
            onOpenChange={vi.fn()}
            onCaptured={vi.fn()}
            onUseAnotherDevice={vi.fn()}
        />,
    );

    await waitFor(() => expect(stop).toHaveBeenCalled());
});

it('offers the other device instead when this one has no camera to open', async () => {
    getUserMedia.mockRejectedValue(new Error('NotAllowedError'));

    renderDialog(true);

    expect(
        await screen.findByText(en['documents.show.camera_unavailable']),
    ).toBeInTheDocument();
    expect(
        screen.queryByRole('button', {
            name: en['documents.show.camera_capture_button'],
        }),
    ).not.toBeInTheDocument();
    expect(
        screen.getByRole('button', {
            name: en['documents.show.camera_use_another_device'],
        }),
    ).toBeInTheDocument();
});

/**
 * Drive the detection loop with a camera that reports a frame, and a scanner
 * answering `detections` one pass at a time.
 *
 * @param detections What `detectCorners` returns on each successive pass.
 *
 * @returns The rendered container, and a function advancing one pass.
 */
async function renderDetecting(detections: (object | null)[]) {
    const detectCorners = vi.fn();

    detections.forEach((detection) =>
        detectCorners.mockReturnValueOnce(detection),
    );
    detectCorners.mockReturnValue(null);

    documentScan.loadScanner.mockResolvedValue({
        detectCorners,
        warp: vi.fn(),
    });
    documentScan.scaleCorners.mockImplementation((corners: object) => corners);
    documentScan.smoothCorners.mockImplementation(
        (_: object, next: object) => next,
    );
    documentScan.isDifferentSubject.mockReturnValue(false);
    documentScan.cornersToPolygon.mockReturnValue('0,0 1,0 1,1 0,1');

    // jsdom reports a video with no frame, and detection is skipped without one.
    for (const [property, value] of [
        ['videoWidth', 1920],
        ['videoHeight', 1080],
    ] as const) {
        Object.defineProperty(HTMLVideoElement.prototype, property, {
            value,
            configurable: true,
        });
    }

    renderDialog(true);

    const advance = async (milliseconds: number) => {
        await act(async () => {
            await vi.advanceTimersByTimeAsync(milliseconds);
        });
    };

    // Lets getUserMedia resolve and the first detection pass run, both of
    // which are microtasks rather than anything on a timer.
    await advance(0);

    return {
        // The dialog renders through a portal, so it is not under the
        // container `render` hands back.
        outline: () => document.body.querySelector('polygon'),
        pass: () => advance(350),
    };
}

// Detection is a fresh guess about a moving picture, and it misses for reasons
// that say nothing about where the page is — a frame caught mid-exposure, a
// hand crossing a corner. Clearing on the first miss turned that into a strobe,
// which is what made the outline unusable to aim with (ARC-117).
it('holds the outline through an isolated missed frame', async () => {
    vi.useFakeTimers();

    try {
        const { outline, pass } = await renderDetecting([
            { topLeft: {}, topRight: {}, bottomLeft: {}, bottomRight: {} },
            null,
        ]);

        expect(outline()).not.toBeNull();

        await pass();

        expect(outline()).not.toBeNull();
    } finally {
        vi.useRealTimers();
    }
});

it('takes the outline down once the page is really gone', async () => {
    vi.useFakeTimers();

    try {
        const { outline, pass } = await renderDetecting([
            { topLeft: {}, topRight: {}, bottomLeft: {}, bottomRight: {} },
        ]);

        expect(outline()).not.toBeNull();

        for (
            let missed = 0;
            missed < documentScan.VIEWFINDER_MISS_TOLERANCE;
            missed++
        ) {
            await pass();
        }

        expect(outline()).toBeNull();
    } finally {
        vi.useRealTimers();
    }
});
