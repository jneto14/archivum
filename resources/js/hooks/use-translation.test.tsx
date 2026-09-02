import { renderHook } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { useTranslation } from '@/hooks/use-translation';
import type { TranslationKey } from '@/lib/translations';

/**
 * The lookup runs against a fixture rather than the real dictionaries because
 * the rung worth testing — a key present in English and missing from another
 * language — cannot be reached through them: `pt` is currently complete, so a
 * test written against it would assert nothing today and break on the day
 * somebody adds an English string first, which is precisely when the fallback
 * has to work.
 */
vi.mock('@/lib/translations', () => ({
    defaultLocale: 'en',
    translations: {
        en: { greeting: 'Hello', only_english: 'Sorry', named: 'Hello :name' },
        pt: { greeting: 'Olá', named: 'Olá :name' },
    },
}));

const page = vi.hoisted(() => ({ props: { locale: 'pt' } }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => page,
}));

/** The fixture's keys are not real TranslationKeys; the lookup never cares. */
function translate(
    key: string,
    replacements?: Record<string, string | number>,
) {
    const { result } = renderHook(() => useTranslation());

    return result.current(key as TranslationKey, replacements);
}

beforeEach(() => {
    page.props.locale = 'pt';
});

it('uses the string for the current locale', () => {
    expect(translate('greeting')).toBe('Olá');
});

it('falls back to English when the current locale has no string for the key', () => {
    expect(translate('only_english')).toBe('Sorry');
});

it('falls back to English for a locale with no dictionary at all', () => {
    page.props.locale = 'de';

    expect(translate('greeting')).toBe('Hello');
});

/**
 * Returning the key is what makes a missing string visible in the interface
 * instead of rendering a blank where a label should be.
 */
it('returns the key itself when nothing has a string for it', () => {
    expect(translate('nothing.knows.this')).toBe('nothing.knows.this');
});

it('substitutes named placeholders', () => {
    expect(translate('named', { name: 'Ana' })).toBe('Olá Ana');
});

it('leaves a placeholder alone when nothing is given for it', () => {
    expect(translate('named')).toBe('Olá :name');
});
