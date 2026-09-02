import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { DatePicker } from '@/components/date-picker';

/**
 * The regression test for ARC-94.
 *
 * The document form used a native `<input type="date">`, which renders in the
 * *browser's* locale and cannot be told otherwise. A Portuguese installation
 * showed `mm/dd/yyyy` and an English calendar to anyone whose browser was set
 * to en-US. It shipped on 2026-08-27 and was found on 2026-09-02 by a person
 * switching language and noticing — six days, for a defect that had already
 * been found and fixed once in the documents filter.
 *
 * Nothing automated could have caught it: `tsc` compiles a native input
 * happily, and a Pest feature test asserts the Inertia component name and its
 * props, then stops at the boundary where the rendering happens.
 *
 * So these assertions are deliberately about what the *app's* locale produces
 * versus what another locale produces, never about a fixed string. Comparing
 * the two renders is what proves the component followed the application rather
 * than its environment; pinning `31 de ago. de 2026` would only prove that ICU
 * has not changed its abbreviations.
 */
const page = vi.hoisted(() => ({ props: { locale: 'en' } }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => page,
}));

beforeEach(() => {
    page.props.locale = 'en';
});

afterEach(() => {
    vi.unstubAllEnvs();
});

function renderPicker(onChange = vi.fn()) {
    return render(
        <DatePicker
            value="2026-08-31"
            onChange={onChange}
            placeholder="Pick a date"
            clearLabel="Clear"
        />,
    );
}

it('shows the selected day in the app locale, not the browser one', () => {
    page.props.locale = 'en';
    const english = renderPicker();
    const inEnglish = screen.getByRole('button', { name: /2026/ }).textContent;
    english.unmount();

    page.props.locale = 'pt';
    renderPicker();
    const inPortuguese = screen.getByRole('button', {
        name: /2026/,
    }).textContent;

    expect(inPortuguese).not.toBe(inEnglish);
    expect(inEnglish).toMatch(/aug/i);
    expect(inPortuguese).toMatch(/ago/i);
});

/**
 * The other half of ARC-94's neighbourhood: a `YYYY-MM-DD` from the server must
 * show as that day, not the one before. See calendar-date.test.ts for why the
 * timezone is the whole test.
 */
it('shows the day the server sent, west of Greenwich', () => {
    vi.stubEnv('TZ', 'America/Los_Angeles');

    renderPicker();

    expect(screen.getByRole('button', { name: /2026/ })).toHaveTextContent(
        /\b31\b/,
    );
});

/**
 * The calendar is the half a person actually reads, and it is where the native
 * input was most obviously wrong: month names and the first column both came
 * from the browser.
 *
 * The first cell is asserted rather than the weekday headings because the
 * headings are `aria-hidden` — 2026-07-27 is a Monday and 2026-07-26 is the
 * Sunday before it, so which one August opens on says where the week starts.
 */
it.each([
    ['pt', /agosto/i, '2026-07-27'],
    ['en', /august/i, '2026-07-26'],
])(
    'opens a calendar in %s, starting the week where that locale starts it',
    async (locale, month, firstCellDay) => {
        page.props.locale = locale;
        renderPicker();

        await userEvent.click(screen.getByRole('button', { name: /2026/ }));

        expect(await screen.findByRole('grid')).toHaveAccessibleName(month);

        const [firstCell] = screen.getAllByRole('gridcell');
        expect(firstCell).toHaveAttribute('data-day', firstCellDay);
    },
);

it('empties the field without opening the calendar', async () => {
    const onChange = vi.fn();
    renderPicker(onChange);

    await userEvent.click(screen.getByRole('button', { name: 'Clear' }));

    expect(onChange).toHaveBeenCalledWith(null);
    expect(screen.queryByRole('grid')).not.toBeInTheDocument();
});
