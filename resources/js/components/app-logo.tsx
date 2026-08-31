import { usePage } from '@inertiajs/react';

import AppLogoIcon from '@/components/app-logo-icon';
import { cn } from '@/lib/utils';

/**
 * The full logo lockup — the mark stacked over the wordmark.
 *
 * This is the brand's front door, used where there is no workspace context to
 * show instead: the auth screens. Inside the app the sidebar header belongs to
 * the workspace switcher, which shows the workspace's own name over the bare
 * mark — the wordmark would compete with it there.
 *
 * The wordmark is set in the brand face (`font-brand`, Newsreader) at regular
 * weight. It takes its colour from the surrounding text colour, so callers set
 * both mark and wordmark with a single text utility.
 */
export default function AppLogo({ className }: { className?: string }) {
    const { name } = usePage().props;

    return (
        <span className={cn('flex flex-col items-center gap-2', className)}>
            <AppLogoIcon className="size-10 fill-current" />
            <span className="font-brand text-3xl leading-none tracking-tight">
                {name}
            </span>
        </span>
    );
}
