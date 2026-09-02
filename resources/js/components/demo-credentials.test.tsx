import { render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { DemoCredentials } from '@/components/demo-credentials';

/**
 * This one leaks a password onto a login screen if it ever renders when it
 * should not — the strongest reason in the app to pin "renders nothing" as a
 * test rather than as a reading of the code.
 */
const page = vi.hoisted(() => ({
    props: {
        locale: 'en',
        demo: null as { email: string; password: string } | null,
    },
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => page,
}));

beforeEach(() => {
    page.props.demo = null;
});

it('renders nothing on an ordinary installation', () => {
    const { container } = render(<DemoCredentials />);

    expect(container).toBeEmptyDOMElement();
});

/**
 * Each value paired with its own label is what stops somebody typing the
 * password into the email field, so the pairing is asserted rather than the
 * two strings merely being present somewhere on the page.
 */
it('labels each credential on a demo', () => {
    page.props.demo = { email: 'demo@archivum.example', password: 'demo1234' };

    render(<DemoCredentials />);

    const [email, password] = screen.getAllByRole('definition');
    expect(email).toHaveTextContent('demo@archivum.example');
    expect(password).toHaveTextContent('demo1234');
    expect(screen.getAllByRole('term')).toHaveLength(2);
});
