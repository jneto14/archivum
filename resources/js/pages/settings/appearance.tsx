import { Head, setLayoutProps } from '@inertiajs/react';
import AppearanceTabs from '@/components/appearance-tabs';
import Heading from '@/components/heading';
import { useTranslation } from '@/hooks/use-translation';
import { edit as editAppearance } from '@/routes/appearance';

export default function Appearance() {
    const t = useTranslation();

    setLayoutProps({
        breadcrumbs: [
            {
                title: t('settings.appearance.head_title'),
                href: editAppearance(),
            },
        ],
    });

    return (
        <>
            <Head title={t('settings.appearance.head_title')} />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('settings.appearance.title')}
                    description={t('settings.appearance.description')}
                />
                <AppearanceTabs />
            </div>
        </>
    );
}
