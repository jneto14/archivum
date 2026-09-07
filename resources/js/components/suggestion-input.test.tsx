import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { expect, it, vi } from 'vitest';
import { SuggestionInput } from '@/components/suggestion-input';

/**
 * The real field is controlled by the document form, so the tests drive it the
 * same way: an uncontrolled render would let a passing test hide the fact that
 * picking a suggestion never reached the value.
 */
function Field({
    suggestions,
    onChange,
}: {
    suggestions: string[];
    onChange?: (value: string) => void;
}) {
    const [value, setValue] = useState('');

    return (
        <SuggestionInput
            placeholder="Key"
            value={value}
            onChange={(next) => {
                setValue(next);
                onChange?.(next);
            }}
            suggestions={suggestions}
            suggestionsLabel="Fields already used"
        />
    );
}

it('offers nothing until the field is focused', () => {
    render(<Field suggestions={['Fornecedor', 'Apólice']} />);

    expect(screen.queryByRole('listbox')).toBeNull();
});

it('offers the suggestions on focus and fills one in when it is clicked', async () => {
    const onChange = vi.fn();
    render(
        <Field suggestions={['Fornecedor', 'Apólice']} onChange={onChange} />,
    );

    await userEvent.click(screen.getByPlaceholderText('Key'));

    expect(
        screen.getByRole('listbox', { name: 'Fields already used' }),
    ).toBeInTheDocument();

    await userEvent.click(screen.getByRole('option', { name: 'Apólice' }));

    expect(onChange).toHaveBeenLastCalledWith('Apólice');
    expect(screen.getByPlaceholderText('Key')).toHaveValue('Apólice');
    // Picking one is the end of the interaction; leaving the list up would
    // cover the row below it.
    expect(screen.queryByRole('listbox')).toBeNull();
});

it('picks a suggestion with the keyboard without submitting the form around it', async () => {
    const onSubmit = vi.fn((event: React.FormEvent) => event.preventDefault());

    render(
        <form onSubmit={onSubmit}>
            <Field suggestions={['Fornecedor', 'Apólice']} />
        </form>,
    );

    await userEvent.click(screen.getByPlaceholderText('Key'));
    await userEvent.keyboard('{ArrowDown}{ArrowDown}{Enter}');

    expect(screen.getByPlaceholderText('Key')).toHaveValue('Apólice');
    expect(onSubmit).not.toHaveBeenCalled();
});

it('lets Enter through when no suggestion is highlighted', async () => {
    const onSubmit = vi.fn((event: React.FormEvent) => event.preventDefault());

    render(
        <form onSubmit={onSubmit}>
            <Field suggestions={['Fornecedor']} />
        </form>,
    );

    await userEvent.click(screen.getByPlaceholderText('Key'));
    await userEvent.keyboard('{Enter}');

    expect(onSubmit).toHaveBeenCalled();
});

it('keeps whatever is typed, suggestion or not', async () => {
    render(<Field suggestions={['Fornecedor']} />);

    await userEvent.type(screen.getByPlaceholderText('Key'), 'Nº de processo');

    expect(screen.getByPlaceholderText('Key')).toHaveValue('Nº de processo');
});

it('closes on Escape and reopens on the next keystroke', async () => {
    render(<Field suggestions={['Fornecedor']} />);

    await userEvent.click(screen.getByPlaceholderText('Key'));
    await userEvent.keyboard('{Escape}');

    expect(screen.queryByRole('listbox')).toBeNull();

    await userEvent.keyboard('F');

    expect(screen.getByRole('listbox')).toBeInTheDocument();
});

it('does not open a list it has nothing to put in', async () => {
    render(<Field suggestions={[]} />);

    await userEvent.click(screen.getByPlaceholderText('Key'));

    expect(screen.queryByRole('listbox')).toBeNull();
    expect(screen.getByPlaceholderText('Key')).toHaveAttribute(
        'aria-expanded',
        'false',
    );
});
