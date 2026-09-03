import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, expect, it, vi } from 'vitest';
import { DocumentScanReview } from '@/components/document-scan-review';
import en from '@/lib/translations/en';

const page = vi.hoisted(() => ({ props: { locale: 'en' } }));
vi.mock('@inertiajs/react', () => ({ usePage: () => page }));

/**
 * `document-scan.ts` wraps jscanify + OpenCV.js, neither of which run
 * meaningfully under jsdom (no real image decoding, no WASM). Mocking the
 * module lets these tests focus on what actually broke before: the review
 * step silently uploading the original photo instead of the straightened
 * one whenever the straightening step failed for any reason.
 */
const documentScan = vi.hoisted(() => ({
    loadOpenCv: vi.fn(),
    detectDocumentCorners: vi.fn(),
    defaultCorners: vi.fn(),
    warpToCorners: vi.fn(),
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
    const utils = render(
        <DocumentScanReview
            file={file}
            onConfirm={onConfirm}
            onRetake={onRetake}
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

    return { file, onConfirm, onRetake, ...utils };
}

beforeEach(() => {
    vi.clearAllMocks();
    documentScan.loadOpenCv.mockResolvedValue({});
    documentScan.detectDocumentCorners.mockReturnValue(detectedCorners);
});

it('uploads the straightened scan, not the original photo, when confirmed', async () => {
    const { onConfirm } = await renderAdjusting();
    const scanned = makeFile('photo-scan.jpg');
    const canvas = document.createElement('canvas');
    documentScan.warpToCorners.mockReturnValue(canvas);
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
    const { file, onConfirm } = await renderAdjusting();
    documentScan.warpToCorners.mockImplementation(() => {
        throw new Error('warp failed');
    });

    await userEvent.click(
        screen.getByRole('button', {
            name: en['capture.confirm_scan_button'],
        }),
    );

    await waitFor(() => expect(onConfirm).toHaveBeenCalledWith(file));
});

it('lets "Use original" skip straightening entirely', async () => {
    const { file, onConfirm } = await renderAdjusting();

    await userEvent.click(
        screen.getByRole('button', {
            name: en['capture.use_original_button'],
        }),
    );

    expect(onConfirm).toHaveBeenCalledWith(file);
    expect(documentScan.warpToCorners).not.toHaveBeenCalled();
});
