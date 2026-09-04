import { render, screen, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { DocumentCameraDialog } from '@/components/document-camera-dialog';
import en from '@/lib/translations/en';

const page = vi.hoisted(() => ({ props: { locale: 'en' } }));
vi.mock('@inertiajs/react', () => ({ usePage: () => page }));

// OpenCV.js does not run under jsdom, and the outline is not what these
// assertions are about — only that the camera is opened and let go of.
const documentScan = vi.hoisted(() => ({
    VIEWFINDER_DETECTION_WIDTH: 480,
    loadScanner: vi.fn(),
    canvasToFile: vi.fn(),
    cornersToPolygon: vi.fn(),
    scaleCorners: vi.fn(),
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
