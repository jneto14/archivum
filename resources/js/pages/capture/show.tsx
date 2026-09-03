import { Head, router } from '@inertiajs/react';
import { CameraIcon, CheckIcon, ScanLineIcon } from 'lucide-react';
import { useRef, useState } from 'react';
import type { ChangeEvent } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { DocumentScanReview } from '@/components/document-scan-review';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';

type Props = {
    document_title: string;
    active: boolean;
    status: 'active' | 'cancelled' | 'completed';
    photos_count: number;
};

/**
 * The same signed URL serves the page and every action on it (see
 * routes/capture.php) — the `signed` middleware validates the request URL as
 * a whole, not tied to a route name, so re-using the current location is what
 * keeps the signature valid on every request.
 */
const actionUrl = () => window.location.pathname + window.location.search;

const endedMessageKey = {
    completed: 'capture.ended_completed',
    cancelled: 'capture.ended_cancelled',
    // Not a real status — an `active` row whose `expires_at` has passed reads
    // as this once expiry is folded in. See DocumentCaptureSession::isActive().
    active: 'capture.ended_expired',
} as const;

/**
 * The phone side of "scan with your phone" (ARC-105). No app shell: this page
 * is opened by scanning a QR code, on a device that was never signed in, so
 * it renders bare full-screen (see the `capture/` case in app.tsx's layout
 * switch) rather than through the desktop app's sidebar shell.
 */
export default function CaptureShow({
    document_title: documentTitle,
    active,
    status,
    photos_count: photosCount,
}: Props) {
    const t = useTranslation();
    const plainInputRef = useRef<HTMLInputElement>(null);
    const scanInputRef = useRef<HTMLInputElement>(null);
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | undefined>(undefined);
    // The just-taken photo, awaiting the "make it look like a scan" review
    // step below — nothing is uploaded until that step's confirmed. Only the
    // "scan" capture button ever sets this; the plain one uploads straight
    // away, exactly like before this review step existed.
    const [pendingPhoto, setPendingPhoto] = useState<File | null>(null);

    const uploadPhoto = (file: File) => {
        setPendingPhoto(null);
        setSending(true);

        router.post(
            actionUrl(),
            { files: [file] },
            {
                forceFormData: true,
                preserveScroll: true,
                onError: (errors) =>
                    setError(
                        errors.files ??
                            Object.values(errors).find(
                                (message) => message !== undefined,
                            ),
                    ),
                onFinish: () => setSending(false),
            },
        );
    };

    const pickPlainPhoto = (event: ChangeEvent<HTMLInputElement>) => {
        const file = event.target.files?.[0];
        event.target.value = '';

        if (file) {
            setError(undefined);
            uploadPhoto(file);
        }
    };

    const pickPhotoToScan = (event: ChangeEvent<HTMLInputElement>) => {
        const file = event.target.files?.[0];
        event.target.value = '';

        if (file) {
            setError(undefined);
            setPendingPhoto(file);
        }
    };

    const markDone = () => {
        router.post(actionUrl(), { done: '1' }, { preserveScroll: true });
    };

    return (
        <div className="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6">
            <Head title={t('capture.head_title')} />

            <div className="flex w-full max-w-sm flex-col items-center gap-6">
                <AppLogoIcon className="size-9 fill-current text-[var(--foreground)] dark:text-white" />

                {active && pendingPhoto ? (
                    <DocumentScanReview
                        file={pendingPhoto}
                        onConfirm={uploadPhoto}
                        onRetake={() => setPendingPhoto(null)}
                    />
                ) : active ? (
                    <>
                        <div className="space-y-2 text-center">
                            <h1 className="text-xl font-medium">
                                {t('capture.title')}
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                {t('capture.description', {
                                    document: documentTitle,
                                })}
                            </p>
                        </div>

                        <input
                            ref={scanInputRef}
                            type="file"
                            accept="image/*"
                            capture="environment"
                            className="sr-only"
                            onChange={pickPhotoToScan}
                        />
                        <input
                            ref={plainInputRef}
                            type="file"
                            accept="image/*"
                            capture="environment"
                            className="sr-only"
                            onChange={pickPlainPhoto}
                        />

                        <div className="flex w-full flex-col gap-2">
                            <Button
                                size="lg"
                                className="h-16 w-full text-base"
                                disabled={sending}
                                onClick={() => scanInputRef.current?.click()}
                            >
                                <ScanLineIcon className="size-5" />
                                {t('capture.take_scan_button')}
                            </Button>
                            <Button
                                variant="outline"
                                disabled={sending}
                                onClick={() => plainInputRef.current?.click()}
                            >
                                <CameraIcon className="size-5" />
                                {sending
                                    ? t('capture.sending')
                                    : t('capture.take_photo_button')}
                            </Button>
                        </div>

                        <InputError message={error} />

                        {photosCount > 0 && (
                            <p className="flex items-center gap-1.5 text-sm text-muted-foreground">
                                <CheckIcon className="size-4 shrink-0" />
                                {photosCount === 1
                                    ? t('capture.sent_count_one', {
                                          count: photosCount,
                                      })
                                    : t('capture.sent_count_other', {
                                          count: photosCount,
                                      })}
                            </p>
                        )}

                        {photosCount > 0 && (
                            <Button
                                variant="ghost"
                                className="w-full"
                                onClick={markDone}
                            >
                                {t('capture.done_button')}
                            </Button>
                        )}
                    </>
                ) : (
                    <div className="space-y-2 text-center">
                        <h1 className="text-xl font-medium">
                            {t('capture.ended_title')}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {t(endedMessageKey[status])}
                        </p>
                    </div>
                )}
            </div>
        </div>
    );
}
