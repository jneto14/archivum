import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, expect, it, vi } from 'vitest';
import { DocumentScanReview } from '@/components/document-scan-review';
import en from '@/lib/translations/en';

const page = vi.hoisted(() => ({ props: { locale: 'en' } }));
vi.mock('@inertiajs/react', () => ({ usePage: () => page }));

// Neither jscanify nor OpenCV.js run meaningfully under jsdom, so the whole
// module is mocked; what matters here is which file `onConfirm` receives.
const scanner = vi.hoisted(() => ({
    detectCorners: vi.fn(),
    warp: vi.fn(),
}));
const documentScan = vi.hoisted(() => ({
    loadScanner: vi.fn(),
    defaultCorners: vi.fn(),
    canvasToFile: vi.fn(),
}));
vi.mock('@/lib/document-scan', () => documentScan);

const detectedCorners = {
    topLeft: { x: 100, y: 100 },
    topRight: { x: 900, y: 100 },
    bottomLeft: { x: 100, y: 700 },
    bottomRight: { x: 900, y: 700 },
};

function makeFile(name = 'photo.jpg'): File {
    return new File(['data'], name, { type: 'image/jpeg' });
}

/** Renders the review step and drives it to the 'adjusting' stage, corners already detected. */
async function renderAdjusting(file = makeFile()) {
    const onConfirm = vi.fn();
    const onRetake = vi.fn();
    const onStraightenFailed = vi.fn();
    const utils = render(
        <DocumentScanReview
            file={file}
            onConfirm={onConfirm}
            onRetake={onRetake}
            onStraightenFailed={onStraightenFailed}
        />,
    );

    const img = utils.container.querySelector('img') as HTMLImageElement;
    Object.defineProperty(img, 'naturalWidth', {
        value: 1000,
        configurable: true,
    });
    Object.defineProperty(img, 'naturalHeight', {
        value: 800,
        configurable: true,
    });
    fireEvent.load(img);

    await screen.findByRole('button', {
        name: en['capture.confirm_scan_button'],
    });

    return { file, onConfirm, onRetake, onStraightenFailed, ...utils };
}

beforeEach(() => {
    vi.clearAllMocks();
    documentScan.loadScanner.mockResolvedValue(scanner);
    scanner.detectCorners.mockReturnValue(detectedCorners);
});

it('uploads the straightened scan, not the original photo, when confirmed', async () => {
    const { onConfirm } = await renderAdjusting();
    const scanned = makeFile('photo-scan.jpg');
    const canvas = document.createElement('canvas');
    scanner.warp.mockReturnValue(canvas);
    documentScan.canvasToFile.mockResolvedValue(scanned);

    await userEvent.click(
        screen.getByRole('button', {
            name: en['capture.confirm_scan_button'],
        }),
    );

    await waitFor(() => expect(onConfirm).toHaveBeenCalledWith(scanned));
    expect(onConfirm).not.toHaveBeenCalledWith(
        expect.objectContaining({ name: 'photo.jpg' }),
    );
});

it('falls back to the original photo if straightening throws, rather than uploading nothing', async () => {
    const { file, onConfirm, onStraightenFailed } = await renderAdjusting();
    scanner.warp.mockImplementation(() => {
        throw new Error('warp failed');
    });

    await userEvent.click(
        screen.getByRole('button', {
            name: en['capture.confirm_scan_button'],
        }),
    );

    await waitFor(() => expect(onConfirm).toHaveBeenCalledWith(file));
    // Otherwise the fallback is indistinguishable from a successful scan.
    expect(onStraightenFailed).toHaveBeenCalledWith('warp failed');
});

it('lets "Use original" skip straightening entirely', async () => {
    const { file, onConfirm } = await renderAdjusting();

    await userEvent.click(
        screen.getByRole('button', {
            name: en['capture.use_original_button'],
        }),
    );

    expect(onConfirm).toHaveBeenCalledWith(file);
    expect(scanner.warp).not.toHaveBeenCalled();
});
