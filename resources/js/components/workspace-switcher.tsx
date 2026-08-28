import { router, usePage } from '@inertiajs/react';
import { ChevronsUpDown } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useIsMobile } from '@/hooks/use-mobile';
import { useTranslation } from '@/hooks/use-translation';
import workspaces from '@/routes/workspaces';

function Tile() {
    return (
        <div className="flex aspect-square size-8 items-center justify-center rounded-md bg-primary text-primary-foreground">
            <AppLogoIcon className="size-4 fill-current text-primary-foreground" />
        </div>
    );
}

export function WorkspaceSwitcher() {
    const t = useTranslation();
    const {
        workspace,
        workspaces: memberships,
        canSwitchWorkspace,
    } = usePage().props;
    const { state } = useSidebar();
    const isMobile = useIsMobile();

    const roleLabels: Record<string, string> = {
        admin: t('nav.workspace_switcher.role_admin'),
        user: t('nav.workspace_switcher.role_member'),
    };

    if (!workspace) {
        return (
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" disabled>
                        <Tile />
                        <span className="flex-1 truncate text-sm text-muted-foreground">
                            {t('nav.workspace_switcher.no_workspace_yet')}
                        </span>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        );
    }

    const current = memberships.find((w) => w.id === workspace.id);
    const canSwitch = canSwitchWorkspace && memberships.length > 1;

    const trigger = (
        <SidebarMenuButton
            size="lg"
            className="group text-sidebar-accent-foreground data-[state=open]:bg-sidebar-accent"
        >
            <Tile />
            <span className="flex-1 flex-col gap-0.5 overflow-hidden text-left leading-tight">
                <span className="block truncate font-semibold">
                    {workspace.name}
                </span>
                <span className="block truncate text-xs text-muted-foreground">
                    {current ? roleLabels[current.role] : null}
                </span>
            </span>
            {canSwitch && <ChevronsUpDown className="ml-auto size-4" />}
        </SidebarMenuButton>
    );

    if (!canSwitch) {
        return (
            <SidebarMenu>
                <SidebarMenuItem>{trigger}</SidebarMenuItem>
            </SidebarMenu>
        );
    }

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>{trigger}</DropdownMenuTrigger>
                    <DropdownMenuContent
                        className="w-(--radix-dropdown-menu-trigger-width) min-w-56 rounded-lg"
                        align="start"
                        side={
                            isMobile
                                ? 'bottom'
                                : state === 'collapsed'
                                  ? 'right'
                                  : 'bottom'
                        }
                    >
                        {memberships.map((w) => (
                            <DropdownMenuItem
                                key={w.id}
                                onClick={() =>
                                    router.post(workspaces.switch.url(w.id))
                                }
                                className="justify-between"
                            >
                                <span className="truncate">{w.name}</span>
                                <span className="text-xs text-muted-foreground">
                                    {roleLabels[w.role]}
                                </span>
                            </DropdownMenuItem>
                        ))}
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
