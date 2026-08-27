import { usePage } from '@inertiajs/react';
import { defaultLocale, translations } from '@/lib/translations';
import type { TranslationKey } from '@/lib/translations';

/**
 * Frontend string lookup, mirroring the backend's __('domain.key')
 * convention. Keys live in resources/js/lib/translations/{locale}.ts,
 * scoped to UI-only strings — server-driven text (validation errors, flash
 * messages) already arrives pre-translated via __() and needs no
 * client-side lookup.
 */
export function useTranslation() {
    const { locale } = usePage().props;
    const dict = translations[locale] ?? translations[defaultLocale];

    return function t(
        key: TranslationKey,
        replacements?: Record<string, string | number>,
    ): string {
        let value = dict[key] ?? translations[defaultLocale][key] ?? key;

        if (replacements) {
            for (const [placeholder, replacement] of Object.entries(
                replacements,
            )) {
                value = value.replace(`:${placeholder}`, String(replacement));
            }
        }

        return value;
    };
}
