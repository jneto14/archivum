import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { PageContainer } from '@/components/page-container';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useTranslation } from '@/hooks/use-translation';
import { cn, toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

export default function SettingsLayout({ children }: PropsWithChildren) {
    const t = useTranslation();
    const { isCurrentOrParentUrl } = useCurrentUrl();

    const sidebarNavItems: NavItem[] = [
        {
            title: t('settings.layout.tab_profile'),
            href: edit(),
            icon: null,
        },
        {
            title: t('settings.layout.tab_security'),
            href: editSecurity(),
            icon: null,
        },
        {
            title: t('settings.layout.tab_appearance'),
            href: editAppearance(),
            icon: null,
        },
    ];

    return (
        <PageContainer>
            <PageHeader
                title={t('settings.layout.title')}
                description={t('settings.layout.description')}
            />

            <div className="flex flex-col gap-8 lg:flex-row">
                <aside className="w-full lg:w-48 lg:shrink-0">
                    <nav
                        className="flex flex-col space-y-1"
                        aria-label={t('settings.layout.title')}
                    >
                        {sidebarNavItems.map((item, index) => {
                            const isActive = isCurrentOrParentUrl(item.href);

                            return (
                                <Button
                                    key={`${toUrl(item.href)}-${index}`}
                                    size="sm"
                                    variant="ghost"
                                    asChild
                                    aria-current={isActive ? 'page' : undefined}
                                    className={cn('w-full justify-start', {
                                        // Mirrors the main sidebar's active
                                        // treatment; `bg-muted` sat *lighter*
                                        // than ghost's `hover:bg-accent`, so
                                        // hovering an inactive tab read as more
                                        // selected than the selected one.
                                        'bg-accent font-medium text-accent-foreground':
                                            isActive,
                                    })}
                                >
                                    <Link href={item.href}>
                                        {item.icon && (
                                            <item.icon className="h-4 w-4" />
                                        )}
                                        {item.title}
                                    </Link>
                                </Button>
                            );
                        })}
                    </nav>
                </aside>

                <Separator className="lg:hidden" />

                <section className="min-w-0 flex-1 space-y-6 lg:max-w-2xl">
                    {children}
                </section>
            </div>
        </PageContainer>
    );
}
