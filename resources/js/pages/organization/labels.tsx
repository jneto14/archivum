import { Head } from '@inertiajs/react';
import { PrinterIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';

type Label = {
    id: string;
    path: string;
    level: string;
    /** The QR code as a data URI, embedded so printing never waits on a fetch. */
    qr: string;
};

type Props = {
    scheme: { id: string; name: string };
    labels: Label[];
};

/**
 * A sheet of physical labels, sized in millimetres rather than pixels: what
 * matters here is how big the sticker comes out of the printer, not how it sits
 * in a viewport. The page prints as it looks, minus the toolbar.
 *
 * Rendered without the app shell (see the layout switch in app.tsx): a sidebar
 * has no business on a print sheet.
 */
export default function OrganizationLabels({ scheme, labels }: Props) {
    const t = useTranslation();

    return (
        <>
            <Head title={t('organization.labels.title')} />

            <style>{`
                @media print {
                    .no-print { display: none !important; }
                    @page { margin: 8mm; }
                }
            `}</style>

            <div className="min-h-screen bg-white p-6 text-black">
                <div className="no-print mb-6 flex flex-wrap items-center gap-3">
                    <div className="min-w-0 flex-1">
                        <h1 className="text-lg font-semibold">
                            {t('organization.labels.title')}
                        </h1>
                        <p className="text-sm text-neutral-600">
                            {t(
                                labels.length === 1
                                    ? 'organization.labels.count_one'
                                    : 'organization.labels.count_other',
                                { count: labels.length, scheme: scheme.name },
                            )}
                        </p>
                    </div>
                    <Button
                        className="shrink-0"
                        onClick={() => window.print()}
                        disabled={labels.length === 0}
                    >
                        <PrinterIcon />
                        {t('organization.labels.print_button')}
                    </Button>
                </div>

                {labels.length === 0 ? (
                    <p className="text-sm text-neutral-600">
                        {t('organization.labels.empty')}
                    </p>
                ) : (
                    <div className="flex flex-wrap gap-[4mm]">
                        {labels.map((label) => (
                            <div
                                key={label.id}
                                // Sized for common adhesive stock, and told not
                                // to break across a page.
                                className="flex h-[30mm] w-[62mm] break-inside-avoid items-center gap-[3mm] rounded border border-neutral-300 p-[3mm]"
                            >
                                <img
                                    src={label.qr}
                                    alt=""
                                    className="h-[24mm] w-[24mm] shrink-0"
                                />
                                <div className="min-w-0 flex-1">
                                    <div className="text-[8pt] tracking-wide text-neutral-500 uppercase">
                                        {label.level}
                                    </div>
                                    <div className="font-mono text-[13pt] leading-tight font-semibold break-words">
                                        {label.path}
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}
