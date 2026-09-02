import { enUS, pt } from 'react-day-picker/locale';

const LOCALES = { pt, en: enUS };

/**
 * First day of the week per app locale, as `react-day-picker` counts it
 * (0 = Sunday).
 *
 * Stated here rather than taken from the date-fns locale object, whose `pt`
 * reports Sunday: it leans Brazilian, while this app's Portuguese is European,
 * where the week starts on Monday. Anything not listed falls back to the
 * library's own default for its locale.
 */
const WEEK_STARTS_ON: Record<string, 0 | 1> = { pt: 1, en: 0 };

/**
 * The `react-day-picker` props that make a calendar follow the *app's* locale
 * rather than the browser's.
 *
 * Shared by every calendar in the app so that a new one cannot quietly ship
 * with the library's defaults — which is how the document form ended up
 * rendering an English calendar to Portuguese installations (ARC-94).
 */
export function calendarLocaleProps(locale: string) {
    return {
        locale: LOCALES[locale as keyof typeof LOCALES] ?? enUS,
        weekStartsOn: WEEK_STARTS_ON[locale],
    };
}
