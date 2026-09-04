import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { expect, it, vi } from 'vitest';
import { MetadataSuggestions } from '@/components/metadata-suggestions';
import en from '@/lib/translations/en';

const page = vi.hoisted(() => ({ props: { locale: 'en' } }));
vi.mock('@inertiajs/react', () => ({ usePage: () => page }));

const suggestions = [
    { kind: 'document_date', key: 'document_date', value: '2026-08-20' },
    { kind: 'amount', key: 'total', value: '1250.50' },
];

function renderSuggestions() {
    const onAccept = vi.fn();

    render(
        <MetadataSuggestions suggestions={suggestions} onAccept={onAccept} />,
    );

    return { onAccept };
}

it('hands the accepted suggestion to the form and stops offering it', async () => {
    const { onAccept } = renderSuggestions();

    await userEvent.click(
        screen.getByRole('button', { name: 'Use 1250.50 for total' }),
    );

    expect(onAccept).toHaveBeenCalledWith(suggestions[1]);
    expect(
        screen.queryByRole('button', { name: 'Use 1250.50 for total' }),
    ).toBeNull();
    // The others are untouched: each suggestion is accepted on its own.
    expect(
        screen.getByRole('button', {
            name: 'Use 2026-08-20 for Document date',
        }),
    ).toBeTruthy();
});

it('writes nothing when a suggestion is ignored', async () => {
    const { onAccept } = renderSuggestions();

    await userEvent.click(
        screen.getByRole('button', { name: 'Ignore the suggestion for total' }),
    );

    expect(onAccept).not.toHaveBeenCalled();
    expect(
        screen.queryByRole('button', { name: 'Use 1250.50 for total' }),
    ).toBeNull();
});

it('renders nothing at all when there is nothing to suggest', () => {
    const { container } = render(
        <MetadataSuggestions suggestions={[]} onAccept={vi.fn()} />,
    );

    // Not an empty panel: a heading with no suggestions under it reads as a
    // feature that failed rather than one with nothing to say.
    expect(container.innerHTML).toBe('');
    expect(
        screen.queryByText(en['documents.form.suggestions_title']),
    ).toBeNull();
});
