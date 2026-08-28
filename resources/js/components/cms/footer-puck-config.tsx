import type { Config, Data } from '@measured/puck';
import { FooterBrand, FooterColumn  } from '@/components/cms/footer-blocks';
import type {FooterLink} from '@/components/cms/footer-blocks';

/**
 * Footer-only Puck config. Deliberately tiny — the footer editor exposes just
 * the brand/tagline and link columns, nothing else. Rendered on the admin canvas
 * with the same components the public footer uses (footer-blocks).
 */

type FooterProps = {
    Brand: { tagline: string; ctaLabel: string; ctaUrl: string };
    Column: { title: string; links: FooterLink[] };
};

export const footerConfig: Config<FooterProps> = {
    components: {
        Brand: {
            fields: {
                tagline: { type: 'textarea' },
                ctaLabel: { type: 'text' },
                ctaUrl: { type: 'text' },
            },
            defaultProps: { tagline: 'Find your people, fill your events.', ctaLabel: 'Create an event', ctaUrl: '/get-started' },
            render: ({ tagline, ctaLabel, ctaUrl }) => <div className="p-2"><FooterBrand tagline={tagline} ctaLabel={ctaLabel} ctaUrl={ctaUrl} /></div>,
        },
        Column: {
            fields: {
                title: { type: 'text' },
                links: {
                    type: 'array',
                    arrayFields: { label: { type: 'text' }, url: { type: 'text' } },
                    defaultItemProps: { label: 'Link', url: '/' },
                    getItemSummary: (item: { label: string }) => item.label || 'Link',
                },
            },
            defaultProps: { title: 'Column', links: [{ label: 'Browse events', url: '/en-my' }] },
            render: ({ title, links }) => <div className="p-2"><FooterColumn title={title} links={links} /></div>,
        },
    },
};

export const emptyFooterData: Data = {
    root: {},
    content: [
        { type: 'Brand', props: { id: 'brand', tagline: 'Find your people, fill your events. Discovery, ticketing, seating and QR check-in — all in one place.', ctaLabel: 'Create an event', ctaUrl: '/get-started' } },
        { type: 'Column', props: { id: 'col-1', title: 'Discover', links: [{ label: 'Browse events', url: '/en-my' }, { label: 'Blog', url: '/blog' }, { label: 'Help center', url: '/help' }] } },
        { type: 'Column', props: { id: 'col-2', title: 'For hosts', links: [{ label: 'Create an event', url: '/get-started' }, { label: 'My tickets', url: '/my/tickets' }, { label: 'Log in', url: '/login' }] } },
    ],
} as Data;
