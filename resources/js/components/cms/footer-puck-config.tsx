import type { Config, Data } from '@measured/puck';
import { DEFAULT_COPYRIGHT, DEFAULT_LEGAL_LINKS, DEFAULT_SUPPORT_EMAIL, Footer } from '@/components/cms/footer-blocks';
import type { FooterLink } from '@/components/cms/footer-blocks';

/**
 * Footer-only Puck config — a single Footer component whose render IS the live
 * footer, so the editor canvas mirrors the site exactly. Columns, the bottom
 * legal links, copyright, support email and the background are all editable.
 */

type FooterProps = {
    Footer: {
        tagline: string; ctaLabel: string; ctaUrl: string;
        columns: { title: string; links: FooterLink[] }[];
        legalLinks: FooterLink[]; copyright: string; supportEmail: string;
        background: 'muted' | 'card' | 'plain';
    };
};

const DEFAULT_COLUMNS = [
    { title: 'Discover', links: [{ label: 'Browse events', url: '/en-my/all' }, { label: 'Blog', url: '/blog' }, { label: 'Help center', url: '/help' }] },
    { title: 'For hosts', links: [{ label: 'Create an event', url: '/get-started' }, { label: 'My tickets', url: '/my/tickets' }, { label: 'Log in', url: '/login' }] },
];

const DEFAULTS = {
    tagline: 'Find your people, fill your events. Discovery, ticketing, seating and QR check-in — all in one place.',
    ctaLabel: 'Create an event',
    ctaUrl: '/get-started',
    columns: DEFAULT_COLUMNS,
    legalLinks: DEFAULT_LEGAL_LINKS,
    copyright: DEFAULT_COPYRIGHT,
    supportEmail: DEFAULT_SUPPORT_EMAIL,
    background: 'muted' as const,
};

const linkField = {
    type: 'array' as const,
    arrayFields: { label: { type: 'text' as const }, url: { type: 'text' as const } },
    defaultItemProps: { label: 'Link', url: '/' },
    getItemSummary: (item: { label: string }) => item.label || 'Link',
};

export const footerConfig: Config<FooterProps> = {
    components: {
        Footer: {
            fields: {
                tagline: { type: 'textarea' },
                supportEmail: { type: 'text' },
                ctaLabel: { type: 'text' },
                ctaUrl: { type: 'text' },
                background: {
                    type: 'select',
                    options: [
                        { label: 'Muted', value: 'muted' },
                        { label: 'Card', value: 'card' },
                        { label: 'Plain', value: 'plain' },
                    ],
                },
                columns: {
                    type: 'array',
                    arrayFields: { title: { type: 'text' }, links: linkField },
                    defaultItemProps: { title: 'Column', links: [{ label: 'Link', url: '/' }] },
                    getItemSummary: (item: { title: string }) => item.title || 'Column',
                },
                legalLinks: linkField,
                copyright: { type: 'text' },
            },
            defaultProps: DEFAULTS,
            render: ({ tagline, ctaLabel, ctaUrl, columns, legalLinks, copyright, supportEmail, background }) => (
                <Footer tagline={tagline} ctaLabel={ctaLabel} ctaUrl={ctaUrl} columns={columns}
                    legalLinks={legalLinks} copyright={copyright} supportEmail={supportEmail} background={background} />
            ),
        },
    },
};

export const emptyFooterData: Data = {
    root: {},
    content: [
        { type: 'Footer', props: { id: 'footer', ...DEFAULTS } },
    ],
} as Data;
