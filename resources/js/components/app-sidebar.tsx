import { usePage } from '@inertiajs/react';
import {
    Activity,
    Archive,
    FileStack,
    FileText,
    FolderTree,
    Gauge,
    LayoutGrid,
    Layers,
    RefreshCw,
    Settings,
    Tag,
    Users,
} from 'lucide-react';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
} from '@/components/ui/sidebar';
import { WorkspaceSwitcher } from '@/components/workspace-switcher';
import { dashboard } from '@/routes';
import { index as documentsIndex } from '@/routes/documents';
import { edit as editProfile } from '@/routes/profile';
import type { NavItem } from '@/types';

export function AppSidebar() {
    const { isWorkspaceAdmin, canSwitchWorkspace, documentsCount, workspace } =
        usePage().props;

    const archiveItems: NavItem[] = [
        { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
        {
            title: 'Documents',
            href: workspace ? documentsIndex.url(workspace.id) : '#',
            icon: FileText,
            disabled: !workspace,
            badge: documentsCount,
        },
        { title: 'Physical storage', href: '#', icon: Archive, disabled: true },
        { title: 'Activity', href: '#', icon: Activity, disabled: true },
        ...(isWorkspaceAdmin
            ? ([
                  {
                      title: 'Jobs & OCR',
                      href: '#',
                      icon: RefreshCw,
                      disabled: true,
                  },
              ] as NavItem[])
            : []),
    ];

    const configItems: NavItem[] = [
        ...(canSwitchWorkspace
            ? ([
                  {
                      title: 'Workspaces',
                      href: '#',
                      icon: Layers,
                      disabled: true,
                  },
              ] as NavItem[])
            : []),
        { title: 'Document types', href: '#', icon: FileStack, disabled: true },
        { title: 'Tags', href: '#', icon: Tag, disabled: true },
        {
            title: 'Import & export',
            href: '#',
            icon: RefreshCw,
            disabled: true,
        },
        {
            title: 'Organization scheme',
            href: '#',
            icon: FolderTree,
            disabled: true,
        },
        { title: 'Users & roles', href: '#', icon: Users, disabled: true },
        { title: 'Usage & limits', href: '#', icon: Gauge, disabled: true },
        { title: 'Settings', href: editProfile(), icon: Settings },
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <WorkspaceSwitcher />
            </SidebarHeader>

            <SidebarContent>
                <NavMain label="Archive" items={archiveItems} />
                {isWorkspaceAdmin && (
                    <NavMain label="Configuration" items={configItems} />
                )}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
