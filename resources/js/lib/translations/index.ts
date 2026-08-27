import en from '@/lib/translations/en';
import pt from '@/lib/translations/pt';

export type TranslationKey = keyof typeof en;

export const translations: Record<
    string,
    Partial<Record<TranslationKey, string>>
> = {
    en,
    pt,
};

export const defaultLocale = 'en';
