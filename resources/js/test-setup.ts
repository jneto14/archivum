import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterEach } from 'vitest';

/**
 * Runs before every test file.
 *
 * `cleanup` unmounts whatever the previous test rendered. Without it the
 * document accumulates every render in the file, and `getByRole` starts
 * failing with "found multiple elements" in the second test that renders the
 * same component — a failure that reads like a bug in the component.
 */
afterEach(() => {
    cleanup();
});
