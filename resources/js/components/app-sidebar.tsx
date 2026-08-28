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
    SlidersHorizontal,
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
import { index as documentTypesIndex } from '@/routes/document-types';
import { index as documentsIndex } from '@/routes/documents';
import {
    create as schemeCreate,
    show as schemeShow,
    storage as schemeStorage,
} from '@/routes/organization/schemes';
import { edit as editProfile } from '@/routes/profile';
import { index as tagsIndex } from '@/routes/tags';
import { show as workspaceShow } from '@/routes/workspaces';
import { show as workspaceSettingsShow } from '@/routes/workspaces/settings';
import { index as usersIndex } from '@/routes/workspaces/users';
import type { NavItem } from '@/types';

export function AppSidebar() {
    const {
        isWorkspaceAdmin,
        canSwitchWorkspace,
        documentsCount,
        workspace,
        organizationSchemeId,
    } = usePage().props;

    const archiveItems: NavItem[] = [
        { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
        {
            title: 'Documents',
            href: workspace ? documentsIndex.url(workspace.id) : '#',
            icon: FileText,
            disabled: !workspace,
            badge: documentsCount,
        },
        {
            title: 'Physical storage',
            href: organizationSchemeId
                ? schemeStorage.url(organizationSchemeId)
                : '#',
            icon: Archive,
            disabled: !organizationSchemeId,
        },
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
        {
            title: 'Document types',
            href: workspace ? documentTypesIndex.url(workspace.id) : '#',
            icon: FileStack,
            disabled: !workspace,
        },
        {
            title: 'Tags',
            href: workspace ? tagsIndex.url(workspace.id) : '#',
            icon: Tag,
            disabled: !workspace,
        },
        {
            title: 'Import & export',
            href: '#',
            icon: RefreshCw,
            disabled: true,
        },
        {
            title: 'Organization scheme',
            href: !workspace
                ? '#'
                : organizationSchemeId
                  ? schemeShow.url(organizationSchemeId)
                  : schemeCreate.url(workspace.id),
            icon: FolderTree,
            disabled: !workspace,
        },
        {
            title: 'Users & roles',
            href: workspace ? usersIndex.url(workspace.id) : '#',
            icon: Users,
            disabled: !workspace,
        },
        {
            title: 'Usage & limits',
            href: workspace ? workspaceShow.url(workspace.id) : '#',
            icon: Gauge,
            disabled: !workspace,
        },
        ...(isWorkspaceAdmin
            ? ([
                  {
                      title: 'Workspace settings',
                      href: workspace
                          ? workspaceSettingsShow.url(workspace.id)
                          : '#',
                      icon: SlidersHorizontal,
                      disabled: !workspace,
                  },
              ] as NavItem[])
            : []),
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
