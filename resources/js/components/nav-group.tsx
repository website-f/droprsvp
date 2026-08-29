import { Link } from '@inertiajs/react';
import { ChevronRight  } from 'lucide-react';
import type {LucideIcon} from 'lucide-react';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import type { NavItem } from '@/types';

/**
 * A collapsible sidebar section: one main category (icon + label) that expands
 * to reveal its links. Auto-opens when it contains the current page. Icons stay
 * on both the header and each sub-link.
 */
export function NavGroup({ label, icon: Icon, items, defaultOpen = false }: { label: string; icon: LucideIcon; items: NavItem[]; defaultOpen?: boolean }) {
    const { isCurrentUrl } = useCurrentUrl();

    if (items.length === 0) {
        return null;
    }

    const containsActive = items.some((i) => isCurrentUrl(i.href));

    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarMenu>
                <Collapsible asChild defaultOpen={defaultOpen || containsActive} className="group/collapsible">
                    <SidebarMenuItem>
                        <CollapsibleTrigger asChild>
                            <SidebarMenuButton tooltip={{ children: label }} isActive={containsActive}>
                                <Icon />
                                <span>{label}</span>
                                <ChevronRight className="ml-auto transition-transform duration-200 group-data-[state=open]/collapsible:rotate-90" />
                            </SidebarMenuButton>
                        </CollapsibleTrigger>
                        <CollapsibleContent>
                            <SidebarMenuSub>
                                {items.map((item) => (
                                    <SidebarMenuSubItem key={item.title}>
                                        <SidebarMenuSubButton asChild isActive={isCurrentUrl(item.href)}>
                                            <Link href={item.href} prefetch>
                                                {item.icon && <item.icon />}
                                                <span>{item.title}</span>
                                            </Link>
                                        </SidebarMenuSubButton>
                                    </SidebarMenuSubItem>
                                ))}
                            </SidebarMenuSub>
                        </CollapsibleContent>
                    </SidebarMenuItem>
                </Collapsible>
            </SidebarMenu>
        </SidebarGroup>
    );
}
