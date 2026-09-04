import type { Auth } from '@/types/auth';
import type { Workspace, WorkspaceMembership } from '@/types/workspace';

declare module 'react' {
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            locale: string;
            auth: Auth;
            sidebarOpen: boolean;
            workspace: Workspace | null;
            workspaces: WorkspaceMembership[];
            /** Only present when this installation is a public demo. */
            demo: {
                email: string;
                password: string;
                /** ISO 8601 instant of the next scheduled wipe. */
                nextResetAt: string;
            } | null;
            canSwitchWorkspace: boolean;
            isWorkspaceAdmin: boolean;
            documentsCount: number | null;
            /** Documents with suggestions plus attachments flagged as duplicates, for the sidebar badge. */
            intakeReviewCount: number | null;
            organizationSchemeId: string | null;
            [key: string]: unknown;
        };
    }
}
