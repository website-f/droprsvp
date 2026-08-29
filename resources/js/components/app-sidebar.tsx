import { Link, usePage } from '@inertiajs/react';
import { Banknote, BookOpen, CalendarDays, ChartColumn, Crown, FileSearch, FileText, FolderGit2, Gauge, Image as ImageIcon, Inbox, LayoutGrid, LayoutTemplate, LifeBuoy, Menu, Newspaper, Palette, PanelBottom, ScrollText, Search, Settings2, ShieldCheck, Ticket, UserRoundCheck, Users, Wallet } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavGroup } from '@/components/nav-group';
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
    { title: 'Following', href: '/following', icon: UserRoundCheck },
];

// Consumer membership — not shown to superadmins (they already have full access).
const premiumNav: NavItem = { title: 'Go Premium', href: '/premium', icon: Crown };

const organizingNav: NavItem[] = [
    { title: 'Events', href: '/host/events', icon: CalendarDays },
    { title: 'Analytics', href: '/host/analytics', icon: ChartColumn },
    { title: 'Payouts', href: '/host/payouts', icon: Wallet },
];

// Superadmin-only groups.
const platformNav: NavItem[] = [
    { title: 'Overview', href: '/admin/overview', icon: Gauge },
    { title: 'Analytics', href: '/admin/analytics', icon: ChartColumn },
    { title: 'All events', href: '/admin/all-events', icon: CalendarDays },
    { title: 'Users', href: '/admin/users', icon: Users },
    { title: 'Payout requests', href: '/admin/payouts', icon: Banknote },
    { title: 'Contact messages', href: '/admin/contact', icon: Inbox },
    { title: 'Settings', href: '/admin/settings', icon: Settings2 },
];

const cmsNav: NavItem[] = [
    { title: 'Pages', href: '/admin/cms/pages', icon: FileText },
    { title: 'Posts', href: '/admin/cms/posts', icon: Newspaper },
    { title: 'Help center', href: '/admin/cms/help', icon: LifeBuoy },
    { title: 'Legal pages', href: '/admin/site/legal', icon: ScrollText },
    { title: 'Homepage SEO', href: '/admin/site/home-seo', icon: Search },
    { title: 'Events SEO', href: '/admin/seo/events', icon: FileSearch },
    { title: 'Menu', href: '/admin/cms/menu', icon: Menu },
];

const siteNav: NavItem[] = [
    { title: 'Branding', href: '/admin/site/branding', icon: ImageIcon },
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
    // Organizing tools (create events, payouts) are for vendors — public/free
    // attendee accounts don't see them. Superadmins always do.
    const isOrganizer = auth?.is_organizer;

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
                {/* Quick access stays flat; the heavy admin areas collapse into
                    one main category each so the sidebar stays manageable. */}
                <NavMain items={isAdmin ? youNav : [...youNav, premiumNav]} label="You" />
                {isOrganizer && <NavGroup label="Organizing" icon={CalendarDays} items={organizingNav} />}
                {isAdmin && <NavGroup label="Platform admin" icon={ShieldCheck} items={platformNav} />}
                {isAdmin && <NavGroup label="Content (CMS)" icon={FileText} items={cmsNav} />}
                {isAdmin && <NavGroup label="Appearance" icon={Palette} items={siteNav} />}
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
