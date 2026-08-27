import { router, usePage } from '@inertiajs/react';
import { SearchIcon } from 'lucide-react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { SidebarTrigger } from '@/components/ui/sidebar';
import {
    create as documentCreate,
    index as documentsIndex,
} from '@/routes/documents';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const { workspace } = usePage().props;

    return (
        <header className="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/50 px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4">
            <div className="flex items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                {workspace && (
                    <span className="text-sm text-muted-foreground">
                        {workspace.name}
                    </span>
                )}
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>

            {workspace && (
                <div className="ml-auto flex items-center gap-2">
                    <div className="relative w-full max-w-64">
                        <SearchIcon className="pointer-events-none absolute top-2.5 left-2.5 size-4 text-muted-foreground" />
                        <Input
                            placeholder="Search documents"
                            className="pl-8"
                            onKeyDown={(event) => {
                                if (event.key === 'Enter') {
                                    router.get(
                                        documentsIndex.url(workspace.id),
                                        {
                                            q: (
                                                event.target as HTMLInputElement
                                            ).value,
                                        },
                                    );
                                }
                            }}
                        />
                    </div>
                    <Button
                        size="sm"
                        onClick={() =>
                            router.visit(documentCreate.url(workspace.id))
                        }
                    >
                        New document
                    </Button>
                </div>
            )}
        </header>
    );
}
