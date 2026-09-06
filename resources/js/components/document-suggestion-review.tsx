import { router } from '@inertiajs/react';
import { useState } from 'react';
import MetadataSuggestionController from '@/actions/App/Http/Controllers/Documents/MetadataSuggestionController';
import type { MetadataSuggestion } from '@/components/metadata-suggestions';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { useTranslation } from '@/hooks/use-translation';
import { show as documentShow } from '@/routes/documents';

export type ReviewableDocument = {
    id: string;
    title: string;
    document_type: string | null;
    suggestions: MetadataSuggestion[];
    /** What OCR made of the page, so a wrong value can be judged against it. */
    ocr_text: string | null;
};

type Props = {
    document: ReviewableDocument;
};

/**
 * One document's row on the review queue: what was read off its scan, ticked or
 * unticked, applied in a single request.
 *
 * Everything starts ticked. The queue exists to be worked through quickly and
 * the values are usually right; making the common case one click, and the
 * exception a click more, is the whole reason this page is faster than opening
 * each document.
 *
 * Only the ticked *kinds* are sent. The server looks their values up again
 * from what it read itself, so this form cannot write anything the application
 * did not find on the page.
 */
export function DocumentSuggestionReview({ document }: Props) {
    const t = useTranslation();
    const [accepted, setAccepted] = useState<string[]>(
        document.suggestions.map((suggestion) => suggestion.kind),
    );
    const [submitting, setSubmitting] = useState(false);
    const [showingText, setShowingText] = useState(false);

    const label = (suggestion: MetadataSuggestion) =>
        suggestion.kind === 'document_date'
            ? t('documents.review.document_date_label')
            : suggestion.key;

    const toggle = (kind: string, checked: boolean) =>
        setAccepted((current) =>
            checked
                ? [...current, kind]
                : current.filter((accepted) => accepted !== kind),
        );

    const submit = (kinds: string[]) => {
        setSubmitting(true);

        router.post(
            MetadataSuggestionController.store.url(document.id),
            { kinds },
            {
                preserveScroll: true,
                onFinish: () => setSubmitting(false),
            },
        );
    };

    return (
        <div className="space-y-3 border-b p-4 last:border-b-0">
            <div className="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    className="min-w-0 flex-1 truncate text-left text-sm font-medium hover:underline"
                    onClick={() => router.visit(documentShow.url(document.id))}
                >
                    {document.title}
                </button>
                {document.document_type && (
                    <span className="shrink-0 text-xs text-muted-foreground">
                        {document.document_type}
                    </span>
                )}
            </div>

            <div className="grid gap-2 sm:grid-cols-2">
                {document.suggestions.map((suggestion) => (
                    <label
                        key={`${suggestion.kind}:${suggestion.key}`}
                        className="flex items-start gap-2 rounded-md border p-2"
                    >
                        <Checkbox
                            className="mt-0.5 shrink-0"
                            checked={accepted.includes(suggestion.kind)}
                            onCheckedChange={(checked) =>
                                toggle(suggestion.kind, checked === true)
                            }
                        />
                        <span className="min-w-0">
                            <span className="block truncate text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                {label(suggestion)}
                            </span>
                            <span className="block truncate text-sm">
                                {suggestion.value}
                            </span>
                        </span>
                    </label>
                ))}
            </div>

            {document.ocr_text && (
                <div>
                    <button
                        type="button"
                        className="text-xs text-muted-foreground hover:underline"
                        onClick={() => setShowingText((showing) => !showing)}
                        aria-expanded={showingText}
                    >
                        {showingText
                            ? t('documents.review.hide_ocr_text')
                            : t('documents.review.show_ocr_text', {
                                  count: document.ocr_text.length,
                              })}
                    </button>
                    {showingText && (
                        // `pre` with wrapping, not a paragraph: the line breaks
                        // are the page's own, and a value is read by the words
                        // in front of it along a line. Reflowed as prose, the
                        // text stops resembling what the reader saw, which is
                        // the whole point of showing it.
                        <pre className="mt-2 max-h-72 overflow-auto rounded-md border bg-muted/40 p-3 font-mono text-xs whitespace-pre-wrap">
                            {document.ocr_text}
                        </pre>
                    )}
                </div>
            )}

            <div className="flex flex-wrap justify-end gap-2">
                <Button
                    variant="ghost"
                    size="sm"
                    className="shrink-0"
                    disabled={submitting}
                    onClick={() => submit([])}
                >
                    {t('documents.review.skip_button')}
                </Button>
                <Button
                    size="sm"
                    className="shrink-0"
                    disabled={submitting || accepted.length === 0}
                    onClick={() => submit(accepted)}
                >
                    {t('documents.review.apply_button', {
                        count: accepted.length,
                    })}
                </Button>
            </div>
        </div>
    );
}
