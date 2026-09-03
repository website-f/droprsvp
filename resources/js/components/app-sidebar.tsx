import { Link, usePage } from '@inertiajs/react';
import { Archive, BadgeCheck, Banknote, CalendarDays, ChartColumn, CircleDollarSign, Crown, FileSearch, FileText, Gauge, Image as ImageIcon, Inbox, LayoutGrid, LayoutTemplate, LifeBuoy, Menu, Newspaper, Palette, PanelBottom, Receipt, ScrollText, Search, Settings2, Shapes, ShieldCheck, Ticket, UserRoundCheck, Users, Wallet } from 'lucide-react';
import AppLogo from '@/components/app-logo';
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
    { title: 'Invoices', href: '/my/invoices', icon: Receipt },
    { title: 'Following', href: '/following', icon: UserRoundCheck },
];

// Consumer membership — not shown to superadmins (they already have full access).
const premiumNav: NavItem = { title: 'Go Premium', href: '/premium', icon: Crown };

const organizingNav: NavItem[] = [
    { title: 'Events', href: '/host/events', icon: CalendarDays },
    { title: 'Analytics', href: '/host/analytics', icon: ChartColumn },
    { title: 'Payouts', href: '/host/payouts', icon: Wallet },
];

// Admin groups — each item tagged with its permission section so staff accounts
// only see what a superadmin has granted them.
type AdminNavItem = NavItem & { section: string };
const platformNav: AdminNavItem[] = [
    { title: 'Overview', href: '/admin/overview', icon: Gauge, section: 'overview' },
    { title: 'Analytics', href: '/admin/analytics', icon: ChartColumn, section: 'analytics' },
    { title: 'All events', href: '/admin/all-events', icon: CalendarDays, section: 'events' },
    { title: 'Categories', href: '/admin/categories', icon: Shapes, section: 'categories' },
    { title: 'Organizers', href: '/admin/organizers', icon: BadgeCheck, section: 'organizers' },
    { title: 'Users', href: '/admin/users', icon: Users, section: 'users' },
    { title: 'Payout requests', href: '/admin/payouts', icon: Banknote, section: 'payouts' },
    { title: 'Finance', href: '/admin/finance', icon: CircleDollarSign, section: 'finance' },
    { title: 'Contact messages', href: '/admin/contact', icon: Inbox, section: 'contact' },
    { title: 'Archive', href: '/admin/archive', icon: Archive, section: 'archive' },
    { title: 'Settings', href: '/admin/settings', icon: Settings2, section: 'settings' },
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

export function AppSidebar() {
    const { auth } = usePage().props;
    const isAdmin = auth?.is_admin;
    const sections = auth?.admin_sections ?? [];
    // Organizing tools (create events, payouts) are for vendors — public/free
    // attendee accounts don't see them. Superadmins always do.
    const isOrganizer = auth?.is_organizer;

    // Staff only see sections they've been granted; superadmin gets everything.
    const platformItems = platformNav.filter((i) => sections.includes(i.section));

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
                <NavMain items={auth?.is_superadmin ? youNav : [...youNav, premiumNav]} label="You" />
                {isOrganizer && <NavGroup label="Organizing" icon={CalendarDays} items={organizingNav} />}
                {isAdmin && platformItems.length > 0 && <NavGroup label="Platform admin" icon={ShieldCheck} items={platformItems} />}
                {isAdmin && sections.includes('content') && <NavGroup label="Content (CMS)" icon={FileText} items={cmsNav} />}
                {isAdmin && sections.includes('appearance') && <NavGroup label="Appearance" icon={Palette} items={siteNav} />}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
