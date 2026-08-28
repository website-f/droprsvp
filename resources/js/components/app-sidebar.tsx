import { Link, usePage } from '@inertiajs/react';
import { Banknote, BookOpen, CalendarDays, ChartColumn, FileText, FolderGit2, Gauge, LayoutGrid, LayoutTemplate, LifeBuoy, Menu, Newspaper, PanelBottom, ScrollText, Search, Ticket, Users, Wallet } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const youNav: NavItem[] = [
    { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
    { title: 'My tickets', href: '/my/tickets', icon: Ticket },
];

const organizingNav: NavItem[] = [
    { title: 'Events', href: '/host/events', icon: CalendarDays },
    { title: 'Payouts', href: '/host/payouts', icon: Wallet },
];

// Superadmin-only groups.
const platformNav: NavItem[] = [
    { title: 'Overview', href: '/admin/overview', icon: Gauge },
    { title: 'Analytics', href: '/admin/analytics', icon: ChartColumn },
    { title: 'All events', href: '/admin/all-events', icon: CalendarDays },
    { title: 'Users', href: '/admin/users', icon: Users },
    { title: 'Payout requests', href: '/admin/payouts', icon: Banknote },
];

const cmsNav: NavItem[] = [
    { title: 'Pages', href: '/admin/cms/pages', icon: FileText },
    { title: 'Posts', href: '/admin/cms/posts', icon: Newspaper },
    { title: 'Help center', href: '/admin/cms/help', icon: LifeBuoy },
    { title: 'Legal pages', href: '/admin/site/legal', icon: ScrollText },
    { title: 'Homepage SEO', href: '/admin/site/home-seo', icon: Search },
    { title: 'Menu', href: '/admin/cms/menu', icon: Menu },
];

const siteNav: NavItem[] = [
    { title: 'Landing', href: '/admin/site/landing', icon: LayoutTemplate },
    { title: 'Footer', href: '/admin/site/footer', icon: PanelBottom },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/react-starter-kit',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#react',
        icon: BookOpen,
    },
];

export function AppSidebar() {
    const { auth } = usePage().props;
    const isAdmin = auth?.is_superadmin;

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                {/* Grouped so the CMS / platform-admin areas are clearly separated. */}
                <NavMain items={youNav} label="You" />
                <NavMain items={organizingNav} label="Organizing" />
                {isAdmin && <NavMain items={platformNav} label="Platform admin" />}
                {isAdmin && <NavMain items={cmsNav} label="Content (CMS)" />}
                {isAdmin && <NavMain items={siteNav} label="Appearance" />}
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
