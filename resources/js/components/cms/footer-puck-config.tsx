import type { Config, Data } from '@measured/puck';
import { Footer  } from '@/components/cms/footer-blocks';
import type {FooterLink} from '@/components/cms/footer-blocks';

/**
 * Footer-only Puck config — a single Footer component whose render IS the live
 * footer, so the editor canvas mirrors the site exactly. Columns (and their
 * links) are editable, reorderable array fields.
 */

type FooterProps = {
    Footer: { tagline: string; ctaLabel: string; ctaUrl: string; columns: { title: string; links: FooterLink[] }[] };
};

const DEFAULT_COLUMNS = [
    { title: 'Discover', links: [{ label: 'Browse events', url: '/en-my/all' }, { label: 'Blog', url: '/blog' }, { label: 'Help center', url: '/help' }] },
    { title: 'For hosts', links: [{ label: 'Create an event', url: '/get-started' }, { label: 'My tickets', url: '/my/tickets' }, { label: 'Log in', url: '/login' }] },
];

export const footerConfig: Config<FooterProps> = {
    components: {
        Footer: {
            fields: {
                tagline: { type: 'textarea' },
                ctaLabel: { type: 'text' },
                ctaUrl: { type: 'text' },
                columns: {
                    type: 'array',
                    arrayFields: {
                        title: { type: 'text' },
                        links: {
                            type: 'array',
                            arrayFields: { label: { type: 'text' }, url: { type: 'text' } },
                            defaultItemProps: { label: 'Link', url: '/' },
                            getItemSummary: (item: { label: string }) => item.label || 'Link',
                        },
                    },
                    defaultItemProps: { title: 'Column', links: [{ label: 'Link', url: '/' }] },
                    getItemSummary: (item: { title: string }) => item.title || 'Column',
                },
            },
            defaultProps: {
                tagline: 'Find your people, fill your events. Discovery, ticketing, seating and QR check-in — all in one place.',
                ctaLabel: 'Create an event',
                ctaUrl: '/get-started',
                columns: DEFAULT_COLUMNS,
            },
            render: ({ tagline, ctaLabel, ctaUrl, columns }) => <Footer tagline={tagline} ctaLabel={ctaLabel} ctaUrl={ctaUrl} columns={columns} />,
        },
    },
};

export const emptyFooterData: Data = {
    root: {},
    content: [
        { type: 'Footer', props: { id: 'footer', tagline: 'Find your people, fill your events. Discovery, ticketing, seating and QR check-in — all in one place.', ctaLabel: 'Create an event', ctaUrl: '/get-started', columns: DEFAULT_COLUMNS } },
    ],
} as Data;
