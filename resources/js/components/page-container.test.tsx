import { render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { PageContainer } from '@/components/page-container';

/**
 * The safety net for a refused write.
 *
 * A validation error is addressed to a field, and a page renders only the ones
 * it has an input for — so a message about a workspace limit, which belongs to
 * no input, used to arrive and be dropped without a trace. Filing into a full
 * workspace did nothing and said nothing.
 *
 * Every page goes through this component, so it is the one place that can show
 * such a message without each page having to remember to. If this stops
 * rendering, refusals go silent again and nothing else fails.
 */
const page = vi.hoisted(() => ({
    props: {
        errors: {} as Record<string, string>,
    },
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => page,
}));

beforeEach(() => {
    page.props.errors = {};
});

it('shows a refusal that belongs to no field', () => {
    page.props.errors = { general: 'This workspace has reached its limit.' };

    render(
        <PageContainer>
            <p>Page content</p>
        </PageContainer>,
    );

    expect(
        screen.getByText('This workspace has reached its limit.'),
    ).toBeInTheDocument();
    expect(screen.getByText('Page content')).toBeInTheDocument();
});

it('stays out of the way when nothing was refused', () => {
    render(
        <PageContainer>
            <p>Page content</p>
        </PageContainer>,
    );

    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
});

/**
 * A message keyed to a field is that field's to render, beside the input it
 * names. Showing it here as well would say the same thing twice on every
 * ordinary validation failure.
 */
it('leaves a message addressed to a field alone', () => {
    page.props.errors = { title: 'The title is required.' };

    render(
        <PageContainer>
            <p>Page content</p>
        </PageContainer>,
    );

    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
});
