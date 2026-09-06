import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, expect, it, vi } from 'vitest';
import { DocumentSuggestionReview } from '@/components/document-suggestion-review';

const page = vi.hoisted(() => ({ props: { locale: 'en' } }));
const router = vi.hoisted(() => ({ post: vi.fn(), visit: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ usePage: () => page, router }));

const document = {
    id: 'doc-1',
    title: 'Scan sem titulo',
    document_type: 'Invoice',
    suggestions: [
        { kind: 'document_date', key: 'document_date', value: '2026-08-20' },
        { kind: 'amount', key: 'total', value: '1250.50' },
    ],
};

/** The kinds sent by the last submission. */
function sentKinds(): string[] {
    const [, payload] = router.post.mock.calls.at(-1) ?? [];

    return (payload as { kinds: string[] }).kinds;
}

beforeEach(() => vi.clearAllMocks());

it('sends every suggestion by default, since they are usually right', async () => {
    render(<DocumentSuggestionReview document={document} />);

    await userEvent.click(screen.getByRole('button', { name: 'Apply (2)' }));

    expect(sentKinds()).toEqual(['document_date', 'amount']);
});

it('leaves out whatever was unticked', async () => {
    render(<DocumentSuggestionReview document={document} />);

    await userEvent.click(screen.getAllByRole('checkbox')[0]);
    await userEvent.click(screen.getByRole('button', { name: 'Apply (1)' }));

    expect(sentKinds()).toEqual(['amount']);
});

it('sends nothing at all when the whole row is dismissed', async () => {
    render(<DocumentSuggestionReview document={document} />);

    await userEvent.click(screen.getByRole('button', { name: 'Nothing here' }));

    // Still a request: the document has been reviewed, which is what takes it
    // off the queue.
    expect(router.post).toHaveBeenCalledTimes(1);
    expect(sentKinds()).toEqual([]);
});

it('cannot apply with nothing ticked', async () => {
    render(<DocumentSuggestionReview document={document} />);

    for (const checkbox of screen.getAllByRole('checkbox')) {
        await userEvent.click(checkbox);
    }

    expect(
        screen
            .getByRole('button', { name: 'Apply (0)' })
            .hasAttribute('disabled'),
    ).toBe(true);
});
