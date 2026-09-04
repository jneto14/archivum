import { CameraIcon, SmartphoneIcon } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { DocumentScanReview } from '@/components/document-scan-review';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { useTranslation } from '@/hooks/use-translation';
import {
    VIEWFINDER_DETECTION_WIDTH,
    canvasToFile,
    cornersToPolygon,
    loadScanner,
    scaleCorners,
} from '@/lib/document-scan';
import type { DocumentCorners } from '@/lib/document-scan';

/**
 * How often the outline is recomputed. Slow enough that detection never queues
 * up behind itself on a phone, fast enough that the outline follows the paper
 * rather than trailing it.
 */
const DETECTION_INTERVAL_MS = 350;

type Size = { width: number; height: number };

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Hands back a straightened page, ready to be queued for upload. */
    onCaptured: (file: File) => void;
    /** Leaves for the QR pairing flow, for a scan taken on some other device. */
    onUseAnotherDevice: () => void;
};

/**
 * Scanning with the camera of the device you are already holding.
 *
 * The flow the QR pairing was built around costs a round trip per page: hand
 * off to the operating system's camera app, shoot, come back, review, and start
 * again for page two — with no idea whether the frame was any good until the
 * photo already exists. Here the camera is inside the app, the page outline is
 * drawn over the live picture while you aim, and confirming a page returns to
 * the viewfinder rather than to a form.
 *
 * The viewfinder is a separate component so that closing the dialog unmounts
 * it: the camera, the pending page and the error state all end with it, rather
 * than being reset by hand on the way back in.
 */
export function DocumentCameraDialog({
    open,
    onOpenChange,
    onCaptured,
    onUseAnotherDevice,
}: Props) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-2xl">
                <Viewfinder
                    onClose={() => onOpenChange(false)}
                    onCaptured={onCaptured}
                    onUseAnotherDevice={() => {
                        onOpenChange(false);
                        onUseAnotherDevice();
                    }}
                />
            </DialogContent>
        </Dialog>
    );
}

type ViewfinderProps = {
    onClose: () => void;
    onCaptured: (file: File) => void;
    onUseAnotherDevice: () => void;
};

/**
 * The live camera, the outline drawn over it, and the review of whatever the
 * shutter kept.
 *
 * Detection runs on a downscaled copy of the frame, because all it has to do is
 * draw a guide. What the shutter keeps is the full-resolution frame, and the
 * crop that actually gets filed is decided by the same review step a
 * photographed page goes through — see `DocumentScanReview`.
 */
function Viewfinder({
    onClose,
    onCaptured,
    onUseAnotherDevice,
}: ViewfinderProps) {
    const t = useTranslation();
    const videoRef = useRef<HTMLVideoElement>(null);
    const streamRef = useRef<MediaStream | null>(null);
    // One canvas reused for every detection pass, rather than one per frame.
    const detectionCanvasRef = useRef<HTMLCanvasElement | null>(null);
    const [frameSize, setFrameSize] = useState<Size | null>(null);
    const [corners, setCorners] = useState<DocumentCorners | null>(null);
    const [cameraFailed, setCameraFailed] = useState(false);
    const [scanError, setScanError] = useState<string | null>(null);
    // The frame the shutter kept, waiting to be cropped and confirmed.
    const [photo, setPhoto] = useState<File | null>(null);
    const [capturedCount, setCapturedCount] = useState(0);

    useEffect(() => {
        let cancelled = false;

        const start = async () => {
            try {
                // `ideal`, not `exact`: a laptop with only a front camera
                // should still open one rather than fail the request outright.
                const stream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: { ideal: 'environment' },
                        width: { ideal: 1920 },
                        height: { ideal: 1080 },
                    },
                });

                if (cancelled) {
                    stopTracks(stream);

                    return;
                }

                streamRef.current = stream;

                if (videoRef.current) {
                    videoRef.current.srcObject = stream;
                }
            } catch {
                // Refused, already in use, or no camera at all. From here they
                // are one situation: there is nothing to aim.
                if (!cancelled) {
                    setCameraFailed(true);
                }
            }
        };

        void start();

        return () => {
            cancelled = true;
            stopTracks(streamRef.current);
            streamRef.current = null;
        };
    }, []);

    useEffect(() => {
        if (cameraFailed || photo !== null) {
            return;
        }

        let stopped = false;
        let timer: number | undefined;

        const detect = async () => {
            const video = videoRef.current;

            if (video && video.videoWidth > 0) {
                const size = {
                    width: video.videoWidth,
                    height: video.videoHeight,
                };

                try {
                    // Memoised, so this is one load and then a resolved promise
                    // — but it is ~13MB the first time, which is why the shutter
                    // never waits for it. Until it lands there is no outline,
                    // and a page shot without one reviews exactly the same.
                    const scanner = await loadScanner();
                    const frame = drawDetectionFrame(
                        video,
                        (detectionCanvasRef.current ??=
                            window.document.createElement('canvas')),
                    );

                    if (stopped) {
                        return;
                    }

                    const detected = scanner.detectCorners(frame);

                    setFrameSize(size);
                    setCorners(
                        detected === null
                            ? null
                            : scaleCorners(
                                  detected,
                                  { width: frame.width, height: frame.height },
                                  size,
                              ),
                    );
                } catch {
                    // Aiming without a guide is the fallback, not a dead end.
                    setCorners(null);
                }
            }

            if (!stopped) {
                timer = window.setTimeout(
                    () => void detect(),
                    DETECTION_INTERVAL_MS,
                );
            }
        };

        void detect();

        return () => {
            stopped = true;
            window.clearTimeout(timer);
        };
    }, [cameraFailed, photo]);

    const capture = useCallback(async () => {
        const video = videoRef.current;

        if (!video || video.videoWidth === 0) {
            return;
        }

        const canvas = window.document.createElement('canvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        canvas.getContext('2d')?.drawImage(video, 0, 0);

        const file = await canvasToFile(canvas, `scan-${Date.now()}.jpg`);

        if (file) {
            setScanError(null);
            setPhoto(file);
        }
    }, []);

    const confirmPhoto = (file: File) => {
        setPhoto(null);
        setCapturedCount((count) => count + 1);
        onCaptured(file);
    };

    return (
        <>
            <DialogTitle>{t('documents.show.camera_dialog_title')}</DialogTitle>
            <DialogDescription>
                {cameraFailed
                    ? t('documents.show.camera_unavailable')
                    : t('documents.show.camera_dialog_description')}
            </DialogDescription>

            {/* Kept mounted while a page is being cropped, so the stream is
                still warm when the viewfinder comes back for the next one. */}
            <div className={photo === null ? undefined : 'hidden'}>
                {!cameraFailed && (
                    <div className="relative h-[55vh] w-full overflow-hidden rounded-md bg-black">
                        <video
                            ref={videoRef}
                            className="h-full w-full object-contain"
                            autoPlay
                            muted
                            // Without this, iOS takes the video into its own
                            // full-screen player the moment it starts.
                            playsInline
                        />
                        {frameSize && corners && (
                            // `xMidYMid meet` is the same fitting `object-contain`
                            // does, so the outline lands on the picture without
                            // anything having to measure where the picture is.
                            <svg
                                className="pointer-events-none absolute inset-0 h-full w-full"
                                viewBox={`0 0 ${frameSize.width} ${frameSize.height}`}
                                preserveAspectRatio="xMidYMid meet"
                                aria-hidden="true"
                            >
                                <polygon
                                    points={cornersToPolygon(corners)}
                                    className="fill-primary/20 stroke-primary"
                                    strokeWidth={
                                        Math.max(
                                            frameSize.width,
                                            frameSize.height,
                                        ) / 180
                                    }
                                    strokeLinejoin="round"
                                />
                            </svg>
                        )}
                    </div>
                )}
            </div>

            {photo !== null && (
                <DocumentScanReview
                    file={photo}
                    onConfirm={confirmPhoto}
                    onRetake={() => setPhoto(null)}
                    onStraightenFailed={setScanError}
                />
            )}

            {scanError !== null && (
                <p className="text-sm text-destructive">{scanError}</p>
            )}

            <DialogFooter className="gap-2 sm:justify-between">
                <Button
                    variant="ghost"
                    className="shrink-0"
                    onClick={onUseAnotherDevice}
                >
                    <SmartphoneIcon className="size-4" />
                    {t('documents.show.camera_use_another_device')}
                </Button>

                <div className="flex flex-wrap items-center justify-end gap-2">
                    {capturedCount > 0 && (
                        <span className="text-sm text-muted-foreground">
                            {capturedCount === 1
                                ? t('documents.show.camera_captured_one', {
                                      count: capturedCount,
                                  })
                                : t('documents.show.camera_captured_other', {
                                      count: capturedCount,
                                  })}
                        </span>
                    )}
                    {photo === null && !cameraFailed && (
                        <Button
                            className="shrink-0"
                            onClick={() => void capture()}
                        >
                            <CameraIcon className="size-4" />
                            {t('documents.show.camera_capture_button')}
                        </Button>
                    )}
                    <Button
                        variant="outline"
                        className="shrink-0"
                        onClick={onClose}
                    >
                        {t('documents.show.camera_close_button')}
                    </Button>
                </div>
            </DialogFooter>
        </>
    );
}

/**
 * Copy the current frame into `canvas`, shrunk to the size detection runs at.
 *
 * @param video The playing camera feed.
 * @param canvas The canvas to reuse for this pass.
 *
 * @returns `canvas`, holding the frame.
 */
function drawDetectionFrame(
    video: HTMLVideoElement,
    canvas: HTMLCanvasElement,
): HTMLCanvasElement {
    const scale = Math.min(1, VIEWFINDER_DETECTION_WIDTH / video.videoWidth);

    canvas.width = Math.round(video.videoWidth * scale);
    canvas.height = Math.round(video.videoHeight * scale);
    canvas
        .getContext('2d')
        ?.drawImage(video, 0, 0, canvas.width, canvas.height);

    return canvas;
}

/** Releases the camera, so the recording indicator goes out with the dialog. */
function stopTracks(stream: MediaStream | null): void {
    stream?.getTracks().forEach((track) => track.stop());
}
