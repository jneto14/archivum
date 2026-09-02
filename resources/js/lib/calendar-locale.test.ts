import { enUS, pt } from 'react-day-picker/locale';
import { describe, expect, it } from 'vitest';
import { calendarLocaleProps } from '@/lib/calendar-locale';

describe('calendarLocaleProps', () => {
    /**
     * The one that is easy to get wrong: date-fns' `pt` leans Brazilian and
     * reports Sunday, while this app's Portuguese is European. Taking the
     * library's word for it would put the wrong column first on every
     * Portuguese installation, which reads as a broken calendar rather than a
     * wrong setting.
     */
    it('starts the Portuguese week on Monday, not on the library default', () => {
        expect(calendarLocaleProps('pt')).toEqual({
            locale: pt,
            weekStartsOn: 1,
        });
    });

    it('starts the English week on Sunday', () => {
        expect(calendarLocaleProps('en')).toEqual({
            locale: enUS,
            weekStartsOn: 0,
        });
    });

    /**
     * A locale added to config/archivum.php but not here must still render a
     * calendar. Leaving `weekStartsOn` undefined hands the decision back to the
     * library, which is a better default than this module's own guess.
     */
    it('falls back to English for a locale it has never heard of', () => {
        expect(calendarLocaleProps('de')).toEqual({
            locale: enUS,
            weekStartsOn: undefined,
        });
    });
});
