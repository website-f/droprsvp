import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | null;
    isActive?: boolean;
};

/** A public-site navigation entry, managed under Admin → Menu. */
export type PublicNavItem = {
    label: string;
    url: string;
    new_tab: boolean;
};

/** Footer config, edited under Admin → Footer. */
export type FooterColumn = { title: string; links: { label: string; url: string }[] };
export type FooterConfig = { tagline: string; columns: FooterColumn[]; copyright: string };

/** Brand logos + per-surface sizing, edited under Admin → Branding. */
export type Branding = {
    logo_full: string;
    logo_mark: string;
    header_height: number;
    sidebar_height: number;
    footer_height: number;
    invert_dark: boolean;
};
