import { usePage } from '@inertiajs/react';

/**
 * Whether this installation is a public demo.
 *
 * Used to leave out affordances the server will refuse anyway — deleting the
 * workspace, editing its limits, creating workspaces, deleting the account.
 * A button that always errors is worse than no button: it reads as the demo
 * being broken rather than as a demo being a demo.
 *
 * This decides what is offered, never what is allowed. The restrictions
 * themselves are DenyInDemoMode on the routes, which is what a page reloaded
 * from cache or a request sent by hand still meets.
 */
export function useIsDemo(): boolean {
    return usePage().props.demo !== null;
}
