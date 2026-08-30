import { router, usePage } from '@inertiajs/react';
import { PlusIcon, SearchIcon } from 'lucide-react';
import { useState } from 'react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useTranslation } from '@/hooks/use-translation';
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
    const t = useTranslation();
    const { workspace } = usePage().props;
    const [searchOpen, setSearchOpen] = useState(false);

    if (!workspace) {
        return (
            <header className="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/50 px-4 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-6">
                <div className="flex min-w-0 items-center gap-2">
                    <SidebarTrigger className="-ml-1" />
                    <Breadcrumbs breadcrumbs={breadcrumbs} />
                </div>
            </header>
        );
    }

    const runSearch = (query: string) => {
        router.get(documentsIndex.url(workspace.id), { q: query });
        setSearchOpen(false);
    };

    return (
        <>
            <header className="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/50 px-4 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-6">
                <div className="flex min-w-0 items-center gap-2">
                    <SidebarTrigger className="-ml-1" />
                    <Breadcrumbs breadcrumbs={breadcrumbs} />
                </div>

                <div className="ml-auto flex shrink-0 items-center gap-2">
                    {/*
                     * The full search field and the labelled action need ~400px
                     * between them, which a phone does not have. Below `md` both
                     * collapse to icons so the breadcrumb keeps its row.
                     */}
                    <div className="relative hidden w-64 md:block">
                        <SearchIcon className="pointer-events-none absolute top-2.5 left-2.5 size-4 text-muted-foreground" />
                        <Input
                            placeholder={t('nav.search_documents')}
                            className="pl-8"
                            onKeyDown={(event) => {
                                if (event.key === 'Enter') {
                                    runSearch(
                                        (event.target as HTMLInputElement)
                                            .value,
                                    );
                                }
                            }}
                        />
                    </div>

                    <Button
                        variant="outline"
                        size="icon"
                        className="md:hidden"
                        aria-label={t('nav.search_documents')}
                        onClick={() => setSearchOpen(true)}
                    >
                        <SearchIcon />
                    </Button>

                    <Button
                        size="sm"
                        className="hidden md:inline-flex"
                        onClick={() =>
                            router.visit(documentCreate.url(workspace.id))
                        }
                    >
                        {t('nav.new_document')}
                    </Button>

                    <Button
                        size="icon"
                        className="md:hidden"
                        aria-label={t('nav.new_document')}
                        onClick={() =>
                            router.visit(documentCreate.url(workspace.id))
                        }
                    >
                        <PlusIcon />
                    </Button>
                </div>
            </header>

            <Dialog open={searchOpen} onOpenChange={setSearchOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('nav.search_documents')}</DialogTitle>
                    </DialogHeader>
                    <Input
                        autoFocus
                        placeholder={t('nav.search_documents')}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter') {
                                runSearch(
                                    (event.target as HTMLInputElement).value,
                                );
                            }
                        }}
                    />
                </DialogContent>
            </Dialog>
        </>
    );
}
