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
import { useTranslation } from '@/hooks/use-translation';
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
import { index as workspacesIndex } from '@/routes/workspaces';
import { show as workspaceSettingsShow } from '@/routes/workspaces/settings';
import { index as usersIndex } from '@/routes/workspaces/users';
import type { NavItem } from '@/types';

export function AppSidebar() {
    const t = useTranslation();
    const {
        auth,
        isWorkspaceAdmin,
        canSwitchWorkspace,
        documentsCount,
        workspace,
        organizationSchemeId,
    } = usePage().props;

    const archiveItems: NavItem[] = [
        {
            title: t('nav.dashboard'),
            href: dashboard(),
            icon: LayoutGrid,
        },
        {
            title: t('nav.documents'),
            href: workspace ? documentsIndex.url(workspace.id) : '#',
            icon: FileText,
            disabled: !workspace,
            badge: documentsCount,
        },
        {
            title: t('nav.physical_storage'),
            href: organizationSchemeId
                ? schemeStorage.url(organizationSchemeId)
                : '#',
            icon: Archive,
            disabled: !organizationSchemeId,
        },
        {
            title: t('nav.activity'),
            href: '#',
            icon: Activity,
            disabled: true,
        },
        ...(isWorkspaceAdmin
            ? ([
                  {
                      title: t('nav.jobs_ocr'),
                      href: '#',
                      icon: RefreshCw,
                      disabled: true,
                  },
              ] as NavItem[])
            : []),
    ];

    const configItems: NavItem[] = [
        ...(canSwitchWorkspace && auth.user.is_platform_admin
            ? ([
                  {
                      title: t('nav.workspaces'),
                      href: workspacesIndex(),
                      icon: Layers,
                  },
              ] as NavItem[])
            : []),
        {
            title: t('nav.document_types'),
            href: workspace ? documentTypesIndex.url(workspace.id) : '#',
            icon: FileStack,
            disabled: !workspace,
        },
        {
            title: t('nav.tags'),
            href: workspace ? tagsIndex.url(workspace.id) : '#',
            icon: Tag,
            disabled: !workspace,
        },
        {
            title: t('nav.import_export'),
            href: '#',
            icon: RefreshCw,
            disabled: true,
        },
        {
            title: t('nav.organization_scheme'),
            href: !workspace
                ? '#'
                : organizationSchemeId
                  ? schemeShow.url(organizationSchemeId)
                  : schemeCreate.url(workspace.id),
            icon: FolderTree,
            disabled: !workspace,
        },
        {
            title: t('nav.users_roles'),
            href: workspace ? usersIndex.url(workspace.id) : '#',
            icon: Users,
            disabled: !workspace,
        },
        {
            title: t('nav.usage_limits'),
            href: '#',
            icon: Gauge,
            disabled: true,
        },
        ...(isWorkspaceAdmin
            ? ([
                  {
                      title: t('nav.workspace_settings'),
                      href: workspace
                          ? workspaceSettingsShow.url(workspace.id)
                          : '#',
                      icon: SlidersHorizontal,
                      disabled: !workspace,
                  },
              ] as NavItem[])
            : []),
        { title: t('nav.settings'), href: editProfile(), icon: Settings },
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <WorkspaceSwitcher />
            </SidebarHeader>

            <SidebarContent>
                <NavMain label={t('nav.group_archive')} items={archiveItems} />
                {(isWorkspaceAdmin ||
                    (canSwitchWorkspace && auth.user.is_platform_admin)) && (
                    <NavMain
                        label={t('nav.group_configuration')}
                        items={configItems}
                    />
                )}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
