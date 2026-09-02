import { render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { DemoBanner } from '@/components/demo-banner';

/**
 * Both of these components are shipped to every installation and are supposed
 * to be invisible on almost all of them. Nothing else in the app fails if this
 * one silently starts rendering — it just puts a banner announcing a public
 * demo across the top of somebody's private archive.
 */
const page = vi.hoisted(() => ({
    props: {
        locale: 'en',
        demo: null as { nextResetAt: string } | null,
    },
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => page,
}));

beforeEach(() => {
    page.props.locale = 'en';
    page.props.demo = null;
});

it('renders nothing on an ordinary installation', () => {
    const { container } = render(<DemoBanner />);

    expect(container).toBeEmptyDOMElement();
});

it('names the deadline on a demo', () => {
    page.props.demo = { nextResetAt: '2026-09-03T04:00:00Z' };

    render(<DemoBanner />);

    // The hour is the point: a demo that wipes without warning punishes the
    // visitor who took it seriously enough to set something up.
    expect(screen.getByText(/2026/)).toHaveTextContent(/sep/i);
});

it('states the deadline in the app locale', () => {
    page.props.demo = { nextResetAt: '2026-09-03T04:00:00Z' };
    const english = render(<DemoBanner />);
    const inEnglish = screen.getByText(/2026/).textContent;
    english.unmount();

    page.props.locale = 'pt';
    render(<DemoBanner />);

    expect(screen.getByText(/2026/).textContent).not.toBe(inEnglish);
});
