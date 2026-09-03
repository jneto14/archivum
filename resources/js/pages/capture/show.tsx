import { Head, router } from '@inertiajs/react';
import { CameraIcon, CheckIcon } from 'lucide-react';
import { useRef, useState } from 'react';
import type { ChangeEvent } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
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
    const fileInputRef = useRef<HTMLInputElement>(null);
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | undefined>(undefined);

    const takePhoto = (event: ChangeEvent<HTMLInputElement>) => {
        const file = event.target.files?.[0];
        event.target.value = '';

        if (!file) {
            return;
        }

        setSending(true);
        setError(undefined);

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

    const markDone = () => {
        router.post(actionUrl(), { done: '1' }, { preserveScroll: true });
    };

    const captureButtonLabel = sending
        ? t('capture.sending')
        : photosCount === 0
          ? t('capture.take_photo_button')
          : t('capture.add_another_button');

    return (
        <div className="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6">
            <Head title={t('capture.head_title')} />

            <div className="flex w-full max-w-sm flex-col items-center gap-6">
                <AppLogoIcon className="size-9 fill-current text-[var(--foreground)] dark:text-white" />

                {active ? (
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
                            ref={fileInputRef}
                            type="file"
                            accept="image/*"
                            capture="environment"
                            className="sr-only"
                            onChange={takePhoto}
                        />

                        <Button
                            size="lg"
                            className="h-16 w-full text-base"
                            disabled={sending}
                            onClick={() => fileInputRef.current?.click()}
                        >
                            <CameraIcon className="size-5" />
                            {captureButtonLabel}
                        </Button>

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
