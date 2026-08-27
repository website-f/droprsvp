import { Link, usePage } from '@inertiajs/react';
import { Banknote, BookOpen, CalendarDays, FileText, FolderGit2, Gauge, LayoutGrid, Menu, Newspaper, Ticket, Users, Wallet } from 'lucide-react';
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

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'My tickets',
        href: '/my/tickets',
        icon: Ticket,
    },
    {
        title: 'Events',
        href: '/host/events',
        icon: CalendarDays,
    },
    {
        title: 'Payouts',
        href: '/host/payouts',
        icon: Wallet,
    },
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
    // The CMS is superadmin-only, so only show it to them.
    const navItems: NavItem[] = auth?.is_superadmin
        ? [...mainNavItems,
            { title: 'Overview', href: '/admin/overview', icon: Gauge },
            { title: 'All events', href: '/admin/all-events', icon: CalendarDays },
            { title: 'Users', href: '/admin/users', icon: Users },
            { title: 'Payout requests', href: '/admin/payouts', icon: Banknote },
            { title: 'Pages', href: '/admin/cms/pages', icon: FileText },
            { title: 'Posts', href: '/admin/cms/posts', icon: Newspaper },
            { title: 'Menu', href: '/admin/cms/menu', icon: Menu }]
        : mainNavItems;

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
                <NavMain items={navItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
