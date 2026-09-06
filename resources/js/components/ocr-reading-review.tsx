import { router } from '@inertiajs/react';
import { useState } from 'react';
import AttachmentController from '@/actions/App/Http/Controllers/Documents/AttachmentController';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { show as documentShow } from '@/routes/documents';

export type OcrReading = {
    id: string;
    filename: string;
    document_id: string;
    document_title: string;
    /** What OCR made of the page, or null where the engine refused it outright. */
    text: string | null;
};

type Props = {
    reading: OcrReading;
};

/**
 * One scan's reading, put in front of somebody to keep or throw away.
 *
 * The engine's confidence answers how sure it was of each word, which is a
 * different question from whether the reading is right — a confident
 * misreading scores as well as a correct one, and only somebody looking at the
 * page can tell them apart. So the text is shown rather than summarised, and
 * the answer is a decision rather than a dismissal.
 *
 * Refusing does not flag the text, it deletes it. `ocr_text` is what feeds the
 * search index and the duplicate fingerprint, so a reading nobody believes has
 * to stop being one.
 */
export function OcrReadingReview({ reading }: Props) {
    const t = useTranslation();
    const [submitting, setSubmitting] = useState(false);

    const answer = (accepted: boolean) => {
        setSubmitting(true);

        const options = {
            preserveScroll: true,
            onFinish: () => setSubmitting(false),
        };

        if (accepted) {
            router.post(
                AttachmentController.confirmOcr.url(reading.id),
                {},
                options,
            );

            return;
        }

        router.delete(AttachmentController.rejectOcr.url(reading.id), options);
    };

    return (
        <div className="space-y-3 border-b p-4 last:border-b-0">
            <div className="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    className="min-w-0 flex-1 truncate text-left text-sm font-medium hover:underline"
                    onClick={() =>
                        router.visit(documentShow.url(reading.document_id))
                    }
                >
                    {reading.document_title}
                </button>
                <span className="shrink-0 text-xs text-muted-foreground">
                    {reading.filename}
                </span>
            </div>

            {reading.text ? (
                // Preformatted, not prose: the line breaks are the page's own,
                // and a value is read by the words in front of it along a line.
                // Reflowed, the text stops resembling what the reader saw,
                // which is the whole point of showing it.
                <pre className="max-h-80 overflow-auto rounded-md border bg-muted/40 p-3 font-mono text-xs whitespace-pre-wrap">
                    {reading.text}
                </pre>
            ) : (
                <p className="rounded-md border border-dashed p-3 text-xs text-muted-foreground">
                    {t('documents.review.reading_empty')}
                </p>
            )}

            <div className="flex flex-wrap justify-end gap-2">
                {reading.text && (
                    <Button
                        variant="ghost"
                        size="sm"
                        className="shrink-0"
                        disabled={submitting}
                        onClick={() => answer(false)}
                    >
                        {t('documents.review.reading_reject')}
                    </Button>
                )}
                <Button
                    size="sm"
                    className="shrink-0"
                    disabled={submitting}
                    onClick={() => answer(true)}
                >
                    {reading.text
                        ? t('documents.review.reading_confirm')
                        : t('documents.review.reading_acknowledge')}
                </Button>
            </div>
        </div>
    );
}
