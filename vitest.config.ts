import { resolve } from 'node:path';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

/**
 * Vitest, kept separate from vite.config.ts on purpose.
 *
 * The application config carries the Laravel and Wayfinder plugins, and
 * Wayfinder shells out to `php artisan` — which needs a database to type route
 * parameters correctly. A test run has no business booting the framework, so
 * this config takes only what a component needs to render: the React transform
 * and the `@/` alias.
 *
 * Stylesheets are left unprocessed (Vitest's default). Nothing here asserts on
 * appearance — a class name is checked, never a computed colour — so compiling
 * Tailwind for every run would buy nothing.
 */
export default defineConfig({
    plugins: [react()],
    resolve: {
        alias: {
            '@': resolve(import.meta.dirname, 'resources/js'),
        },
    },
    test: {
        environment: 'jsdom',
        setupFiles: ['./resources/js/test-setup.ts'],
        include: ['resources/js/**/*.test.{ts,tsx}'],
        restoreMocks: true,
    },
});
