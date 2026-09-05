import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, expect, it, vi } from 'vitest';
import { SortableTableHead, tableSort } from '@/components/sortable-table';
import type { SortState } from '@/components/sortable-table';
import { Table, TableHeader, TableRow } from '@/components/ui/table';

const get = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { locale: 'en' } }),
    router: { get },
}));

beforeEach(() => {
    get.mockClear();
});

const COLUMNS = [
    { key: 'title', label: 'Title' },
    { key: 'date', label: 'Date', descendingFirst: true },
];

function Heads({ sort }: { sort: SortState }) {
    const sorting = tableSort('/documents', sort, COLUMNS);

    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <SortableTableHead sortKey="title" sorting={sorting}>
                        Title
                    </SortableTableHead>
                    <SortableTableHead sortKey="date" sorting={sorting}>
                        Date
                    </SortableTableHead>
                </TableRow>
            </TableHeader>
        </Table>
    );
}

/** What is sorted, and which way, has to be legible without seeing the arrow. */
it('marks only the active column, and says which way it runs', () => {
    render(<Heads sort={{ key: 'title', direction: 'asc' }} />);

    expect(screen.getByRole('columnheader', { name: 'Title' })).toHaveAttribute(
        'aria-sort',
        'ascending',
    );
    expect(screen.getByRole('columnheader', { name: 'Date' })).toHaveAttribute(
        'aria-sort',
        'none',
    );
});

it('reverses the column that is already active', async () => {
    render(<Heads sort={{ key: 'title', direction: 'asc' }} />);

    await userEvent.click(screen.getByRole('button', { name: 'Title' }));

    expect(get).toHaveBeenCalledWith(
        '/documents',
        expect.objectContaining({ sort: 'title', direction: 'desc' }),
        expect.anything(),
    );
});

/**
 * A column is entered from the end it is usually read from. Clicking "Date" and
 * being shown the oldest document in the archive is not what anyone meant.
 */
it('enters another column at the end that column is read from', async () => {
    render(<Heads sort={{ key: 'title', direction: 'asc' }} />);

    await userEvent.click(screen.getByRole('button', { name: 'Date' }));

    expect(get).toHaveBeenCalledWith(
        '/documents',
        expect.objectContaining({ sort: 'date', direction: 'desc' }),
        expect.anything(),
    );
});

it('enters a plain column ascending', async () => {
    render(<Heads sort={{ key: 'date', direction: 'desc' }} />);

    await userEvent.click(screen.getByRole('button', { name: 'Title' }));

    expect(get).toHaveBeenCalledWith(
        '/documents',
        expect.objectContaining({ sort: 'title', direction: 'asc' }),
        expect.anything(),
    );
});
