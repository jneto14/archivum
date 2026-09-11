import {
    ChevronRightIcon,
    CopyIcon,
    FileTextIcon,
    TagIcon,
} from 'lucide-react';
import { useState } from 'react';
import { DocumentSuggestionReview } from '@/components/document-suggestion-review';
import type { ReviewableDocument } from '@/components/document-suggestion-review';
import { OcrReadingReview } from '@/components/ocr-reading-review';
import type { OcrReading } from '@/components/ocr-reading-review';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

export type ReviewDuplicate = {
    id: string;
    filename: string;
    duplicate_of: {
        document_id: string;
        document_title: string | null;
    };
};

export type ReviewRowDocument = ReviewableDocument & {
    readings: OcrReading[];
    duplicates: ReviewDuplicate[];
    /** Suggestions plus readings plus duplicate warnings, for the summary. */
    waiting: number;
};

type Props = {
    document: ReviewRowDocument;
    selected: boolean;
    onSelectedChange: (selected: boolean) => void;
    onDismissDuplicate: (attachmentId: string) => void;
    onOpenOriginal: (documentId: string) => void;
};

/**
 * One document on the review queue, with everything waiting on it.
 *
 * Collapsed by default and summarised as counts, because after a bulk
 * re-extraction the page is thousands of rows and nobody reads thousands of
 * anything. The expansion is where the individual answers live — including
 * the two a bulk action deliberately cannot give, confirming a reading and
 * refusing one, which need the text in front of a person (ARC-118).
 */
export function ReviewRow({
    document,
    selected,
    onSelectedChange,
    onDismissDuplicate,
    onOpenOriginal,
}: Props) {
    const t = useTranslation();
    const [open, setOpen] = useState(false);

    const summary = [
        {
            key: 'suggestions',
            icon: TagIcon,
            count: document.suggestions.length,
            label: t('documents.review.summary_suggestions', {
                count: document.suggestions.length,
            }),
        },
        {
            key: 'readings',
            icon: FileTextIcon,
            count: document.readings.length,
            label: t('documents.review.summary_readings', {
                count: document.readings.length,
            }),
        },
        {
            key: 'duplicates',
            icon: CopyIcon,
            count: document.duplicates.length,
            label: t('documents.review.summary_duplicates', {
                count: document.duplicates.length,
            }),
        },
    ].filter((entry) => entry.count > 0);

    return (
        <div className="border-b last:border-b-0">
            <div className="flex flex-wrap items-start gap-3 p-4">
                <Checkbox
                    checked={selected}
                    onCheckedChange={(value) =>
                        onSelectedChange(value === true)
                    }
                    aria-label={t('documents.review.select_document', {
                        title: document.title,
                    })}
                    className="mt-1 shrink-0"
                />

                <button
                    type="button"
                    onClick={() => setOpen((current) => !current)}
                    aria-expanded={open}
                    className="flex min-w-0 flex-1 items-start gap-2 text-left"
                >
                    <ChevronRightIcon
                        className={cn(
                            'mt-0.5 size-4 shrink-0 text-muted-foreground transition-transform',
                            open && 'rotate-90',
                        )}
                    />
                    <span className="min-w-0 flex-1">
                        <span className="block truncate text-sm font-medium">
                            {document.title}
                        </span>
                        <span className="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                            {document.document_type && (
                                <span className="truncate">
                                    {document.document_type}
                                </span>
                            )}
                            {summary.map((entry) => (
                                <span
                                    key={entry.key}
                                    className="flex items-center gap-1 whitespace-nowrap"
                                >
                                    <entry.icon className="size-3.5" />
                                    {entry.label}
                                </span>
                            ))}
                        </span>
                    </span>
                </button>
            </div>

            {open && (
                <div className="space-y-4 border-t bg-muted/40 px-4 py-4">
                    {document.suggestions.length > 0 && (
                        <div className="overflow-hidden rounded-lg border bg-background">
                            <DocumentSuggestionReview document={document} />
                        </div>
                    )}

                    {document.readings.map((reading) => (
                        <div
                            key={reading.id}
                            className="overflow-hidden rounded-lg border bg-background"
                        >
                            <OcrReadingReview reading={reading} />
                        </div>
                    ))}

                    {document.duplicates.map((duplicate) => (
                        <div
                            key={duplicate.id}
                            className="flex flex-wrap items-center gap-2 rounded-lg border bg-background p-4"
                        >
                            <p className="min-w-0 flex-1 text-xs text-muted-foreground">
                                {t('documents.review.duplicate_of', {
                                    filename: duplicate.filename,
                                    document:
                                        duplicate.duplicate_of.document_title ??
                                        '—',
                                })}
                            </p>
                            <Button
                                variant="outline"
                                size="sm"
                                className="shrink-0"
                                onClick={() =>
                                    onOpenOriginal(
                                        duplicate.duplicate_of.document_id,
                                    )
                                }
                            >
                                {t('documents.review.open_original')}
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                className="shrink-0"
                                onClick={() => onDismissDuplicate(duplicate.id)}
                            >
                                {t('documents.review.keep_both')}
                            </Button>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
