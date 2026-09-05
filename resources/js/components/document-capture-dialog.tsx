import { router, usePoll } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import type { CameraAccess } from '@/hooks/use-camera-access';
import { useTranslation } from '@/hooks/use-translation';
import type { TranslationKey } from '@/lib/translations';
import {
    cancel as cancelCaptureSession,
    qrCode as captureSessionQrCode,
    store as createCaptureSession,
} from '@/routes/capture-sessions';

/**
 * Why this dialog was reached instead of the viewfinder. The reason is not
 * interchangeable: a browser that refused a camera, a device that has none, and
 * a desktop that simply is not the right thing to aim at a page are three
 * different answers, and only one of them is a fault.
 */
const CAMERA_REASONS: Record<
    Exclude<CameraAccess, 'available'>,
    TranslationKey
> = {
    insecure: 'documents.show.camera_needs_secure_connection',
    unavailable: 'documents.show.camera_none_on_this_device',
    'not-handheld': 'documents.show.camera_not_handheld',
};

type ActiveCaptureSession = {
    id: string;
    photos_count: number;
} | null;

type Props = {
    documentId: string;
    activeSession: ActiveCaptureSession;
    /** Why the scan button sent the user here instead of opening a viewfinder. */
    cameraAccess: CameraAccess;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

/**
 * "Scan with your phone" pairing dialog. Fully self-contained: opening it is
 * all the parent page has to do — starting the session, polling its status
 * and ending it again all happen here, driven by the document page's own
 * `active_capture_session` prop (see DocumentController::show()).
 */
export function DocumentCaptureDialog({
    documentId,
    activeSession,
    cameraAccess,
    open,
    onOpenChange,
}: Props) {
    const t = useTranslation();

    // Guards the create request against firing twice: once for the effect
    // below, and again if `open` toggles before the first request lands.
    const startingSessionRef = useRef(false);

    // A convenience refresh, not a live feed — worth far fewer requests.
    const { start, stop } = usePoll(
        5000,
        { only: ['document', 'active_capture_session'] },
        { autoStart: false },
    );

    useEffect(() => {
        if (open) {
            start();
        } else {
            stop();
        }

        return stop;
    }, [open, start, stop]);

    // Opening with no session running starts one, including after an earlier
    // session ended — that's how a new QR code is issued.
    useEffect(() => {
        if (!open || activeSession !== null || startingSessionRef.current) {
            return;
        }

        startingSessionRef.current = true;

        router.post(
            createCaptureSession.url(documentId),
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => {
                    startingSessionRef.current = false;
                },
            },
        );
    }, [open, activeSession, documentId]);

    const endSession = () => {
        // Closed before the request goes out: closing afterwards leaves a
        // window where the effect above starts a replacement session.
        onOpenChange(false);

        if (activeSession !== null) {
            router.post(
                cancelCaptureSession.url([documentId, activeSession.id]),
                {},
                { preserveScroll: true },
            );
        }
    };

    const photosCountLabel = (count: number) => {
        if (count === 0) {
            return t('documents.show.capture_photos_count_zero');
        }

        return count === 1
            ? t('documents.show.capture_photos_count_one', { count })
            : t('documents.show.capture_photos_count_other', { count });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => (next ? onOpenChange(true) : endSession())}
        >
            <DialogContent>
                <DialogTitle>
                    {t('documents.show.capture_dialog_title')}
                </DialogTitle>
                <DialogDescription>
                    {t('documents.show.capture_dialog_description')}
                </DialogDescription>

                {/* Landing here on a device that looks like it has a camera is
                    the confusing case: the scanner simply never appears, and
                    nothing says the browser refused rather than the feature
                    being missing. */}
                {cameraAccess !== 'available' && (
                    <p className="text-sm text-muted-foreground">
                        {t(CAMERA_REASONS[cameraAccess])}
                    </p>
                )}

                {activeSession ? (
                    <div className="flex flex-col items-center gap-3 py-2">
                        <img
                            src={captureSessionQrCode.url([
                                documentId,
                                activeSession.id,
                            ])}
                            alt={t('documents.show.capture_qr_alt')}
                            width={220}
                            height={220}
                            className="rounded-md border"
                        />
                        <p className="text-sm text-muted-foreground">
                            {photosCountLabel(activeSession.photos_count)}
                        </p>
                    </div>
                ) : (
                    <p className="py-2 text-sm text-muted-foreground">
                        {t('documents.show.capture_status_cancelled')}
                    </p>
                )}

                <DialogFooter>
                    <Button variant="ghost" onClick={endSession}>
                        {activeSession
                            ? t('documents.show.capture_end_button')
                            : t('documents.show.capture_close_button')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
