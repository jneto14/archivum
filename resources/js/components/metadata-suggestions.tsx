import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';

export type MetadataSuggestion = {
    /** What was recognised. Only 'document_date' is special: it targets the document's own date field rather than a metadata key. */
    kind: string;
    /** The field the value belongs in — a metadata key, or 'document_date'. */
    key: string;
    value: string;
};

type Props = {
    suggestions: MetadataSuggestion[];
    onAccept: (suggestion: MetadataSuggestion) => void;
};

/**
 * Values read out of the document's attachments, offered for the user to
 * accept.
 *
 * Accepting one only fills the field in the form around it — the document is
 * still saved by the user, deliberately: a value guessed from OCR is a
 * proposal, and writing it without being asked would make a wrong guess
 * indistinguishable from something they typed.
 */
export function MetadataSuggestions({ suggestions, onAccept }: Props) {
    const t = useTranslation();
    const [handled, setHandled] = useState<string[]>([]);

    const identify = (suggestion: MetadataSuggestion) =>
        `${suggestion.kind}:${suggestion.key}`;

    const remaining = suggestions.filter(
        (suggestion) => !handled.includes(identify(suggestion)),
    );

    if (remaining.length === 0) {
        return null;
    }

    const label = (suggestion: MetadataSuggestion) =>
        suggestion.kind === 'document_date'
            ? t('documents.form.suggestion_document_date_label')
            : suggestion.key;

    const handle = (suggestion: MetadataSuggestion, accept: boolean) => {
        if (accept) {
            onAccept(suggestion);
        }

        setHandled([...handled, identify(suggestion)]);
    };

    return (
        <div className="space-y-2 rounded-md border border-dashed p-3">
            <div>
                <p className="text-sm font-medium">
                    {t('documents.form.suggestions_title')}
                </p>
                <p className="text-xs text-muted-foreground">
                    {t('documents.form.suggestions_description')}
                </p>
            </div>
            {remaining.map((suggestion) => (
                <div
                    key={identify(suggestion)}
                    className="flex flex-wrap items-center gap-2"
                >
                    <div className="min-w-0 flex-1">
                        <div className="truncate text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            {label(suggestion)}
                        </div>
                        <div className="truncate text-sm">
                            {suggestion.value}
                        </div>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="shrink-0"
                        aria-label={t('documents.form.suggestion_use_label', {
                            value: suggestion.value,
                            field: label(suggestion),
                        })}
                        onClick={() => handle(suggestion, true)}
                    >
                        {t('documents.form.suggestion_use_button')}
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="shrink-0"
                        aria-label={t(
                            'documents.form.suggestion_ignore_label',
                            {
                                field: label(suggestion),
                            },
                        )}
                        onClick={() => handle(suggestion, false)}
                    >
                        {t('documents.form.suggestion_ignore_button')}
                    </Button>
                </div>
            ))}
        </div>
    );
}
