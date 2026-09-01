import { FieldLabel } from '@measured/puck';
import type { Config, CustomField, Data } from '@measured/puck';
import {
    BarChart3, Building2, HelpCircle, Image as ImageIcon, Images,
    ImageUp, LayoutGrid, Loader2, Megaphone, Minus, MousePointerClick, Newspaper, Pilcrow, Quote, ListOrdered,
    Sparkles, StretchVertical, Trash2, Type, Video as VideoIcon,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { uploadImage } from '@/lib/upload';
import { renderComponents } from './puck-render';
import type { PostCard, Props } from './puck-render';

/**
 * The DropRSVP page-builder configuration for the admin Puck editor.
 *
 * The widget RENDER functions live in `puck-render.tsx` (shared with the public
 * `<Render>` so what you build is exactly what visitors see). This module layers
 * the editor-only pieces on top: the real image uploader field, the per-widget
 * `fields`/`defaultProps`, and the component drawer icons. Public pages never
 * import this module, so they never pull the heavy editor field code.
 */

/** Re-exported so existing imports of these types from `./puck-config` keep working. */
export type { PostCard, Props };

const ALIGN = { type: 'radio' as const, options: [{ label: 'Left', value: 'text-left' }, { label: 'Center', value: 'text-center' }] };
const BG = {
    type: 'select' as const,
    options: [
        { label: 'None', value: 'none' },
        { label: 'Muted', value: 'muted' },
        { label: 'Dark', value: 'dark' },
        { label: 'Primary', value: 'primary' },
    ],
};

const OVERLAY = {
    type: 'select' as const,
    options: [
        { label: 'None', value: 0 },
        { label: 'Light', value: 25 },
        { label: 'Medium', value: 40 },
        { label: 'Strong', value: 60 },
        { label: 'Heavy', value: 75 },
    ],
};

/* ---- custom field: real image uploader (→ /uploads) --------------------- */

function ImageUploadField({ name, value, onChange }: { name: string; value: string; onChange: (v: string) => void }) {
    'use no memo';
    const [busy, setBusy] = useState(false);
    const pick = () => {
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/*';
        input.onchange = async () => {
            const file = input.files?.[0];

            if (!file) {
                return;
            }

            setBusy(true);

            try {
                onChange(await uploadImage(file));
            } catch {
                toast.error('Upload failed — try a smaller image.');
            } finally {
                setBusy(false);
            }
        };
        input.click();
    };

    return (
        <FieldLabel label={name}>
            {value ? (
                <div className="overflow-hidden rounded-lg border border-border">
                    <img src={value} alt="" className="h-28 w-full object-cover" />
                    <div className="flex">
                        <button type="button" onClick={pick} disabled={busy} className="flex flex-1 items-center justify-center gap-1.5 border-t border-border py-2 text-xs font-medium hover:bg-muted">
                            {busy ? <Loader2 className="size-3.5 animate-spin" /> : <ImageUp className="size-3.5" />} Replace
                        </button>
                        <button type="button" onClick={() => onChange('')} className="flex items-center justify-center gap-1.5 border-l border-t border-border px-3 py-2 text-xs font-medium text-destructive hover:bg-muted">
                            <Trash2 className="size-3.5" /> Remove
                        </button>
                    </div>
                </div>
            ) : (
                <button type="button" onClick={pick} disabled={busy} className="flex w-full flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-border py-6 text-sm text-muted-foreground hover:bg-muted">
                    {busy ? <Loader2 className="size-5 animate-spin" /> : <ImageUp className="size-5" />}
                    {busy ? 'Uploading…' : 'Upload image'}
                </button>
            )}
        </FieldLabel>
    );
}

const imageUpload: CustomField<string> = {
    type: 'custom',
    render: ({ name, onChange, value }) => <ImageUploadField name={name} value={value ?? ''} onChange={onChange} />,
};

/* ---- config ------------------------------------------------------------ */

export const config: Config<Props> = {
    categories: {
        sections: { title: 'Sections', components: ['Hero', 'Features', 'Steps', 'CTA', 'FAQ', 'Carousel', 'Video', 'Posts'] },
        content: { title: 'Content', components: ['Heading', 'Text', 'Button', 'Image', 'Gallery', 'Testimonial', 'Stats', 'Logos'] },
        layout: { title: 'Layout', components: ['Spacer', 'Divider'] },
    },
    components: {
        Hero: {
            ...renderComponents.Hero,
            fields: {
                title: { type: 'text' },
                subtitle: { type: 'textarea' },
                buttonText: { type: 'text' },
                buttonUrl: { type: 'text' },
                align: ALIGN,
                background: BG,
                bgImage: imageUpload,
                overlay: OVERLAY,
            },
            defaultProps: { title: 'Find your people', subtitle: 'Discover events near you, buy tickets and check in with a QR pass.', buttonText: 'Browse events', buttonUrl: '/events', align: 'text-center', background: 'none', bgImage: '', overlay: 40 },
        },
        Heading: {
            ...renderComponents.Heading,
            fields: { text: { type: 'text' }, level: { type: 'radio', options: [{ label: 'H1', value: 'h1' }, { label: 'H2', value: 'h2' }, { label: 'H3', value: 'h3' }] }, align: ALIGN },
            defaultProps: { text: 'Section heading', level: 'h2', align: 'text-left' },
        },
        Text: {
            ...renderComponents.Text,
            fields: { text: { type: 'textarea' }, align: ALIGN },
            defaultProps: { text: 'Write a paragraph about your event, venue or organisation here.', align: 'text-left' },
        },
        Button: {
            ...renderComponents.Button,
            fields: { label: { type: 'text' }, url: { type: 'text' }, variant: { type: 'radio', options: [{ label: 'Solid', value: 'solid' }, { label: 'Outline', value: 'outline' }] }, align: ALIGN },
            defaultProps: { label: 'Get tickets', url: '/events', variant: 'solid', align: 'text-left' },
        },
        Image: {
            ...renderComponents.Image,
            fields: {
                url: imageUpload,
                alt: { type: 'text' },
                width: { type: 'radio', options: [{ label: 'Container', value: 'container' }, { label: 'Full width', value: 'full' }] },
                rounded: { type: 'radio', options: [{ label: 'Rounded', value: true }, { label: 'Square', value: false }] },
            },
            defaultProps: { url: '', alt: '', width: 'container', rounded: true },
        },
        Gallery: {
            ...renderComponents.Gallery,
            fields: {
                images: { type: 'array', arrayFields: { image: imageUpload }, defaultItemProps: { image: '' }, getItemSummary: (_i, i) => `Image ${(i ?? 0) + 1}` },
                columns: { type: 'radio', options: [{ label: '2', value: '2' }, { label: '3', value: '3' }, { label: '4', value: '4' }] },
            },
            defaultProps: { images: [{ image: '' }, { image: '' }], columns: '3' },
        },
        Carousel: {
            ...renderComponents.Carousel,
            fields: {
                slides: { type: 'array', arrayFields: { image: imageUpload, caption: { type: 'text' } }, defaultItemProps: { image: '', caption: '' }, getItemSummary: (item: { caption: string }, i) => item.caption || `Slide ${(i ?? 0) + 1}` },
            },
            defaultProps: { slides: [{ image: '', caption: '' }] },
        },
        Features: {
            ...renderComponents.Features,
            fields: {
                items: {
                    type: 'array',
                    arrayFields: {
                        icon: { type: 'select', options: [{ label: 'Ticket', value: 'Ticket' }, { label: 'Users', value: 'Users' }, { label: 'QR', value: 'QrCode' }, { label: 'Calendar', value: 'Calendar' }] },
                        title: { type: 'text' },
                        text: { type: 'textarea' },
                    },
                    defaultItemProps: { icon: 'Ticket', title: 'Feature', text: 'Describe this feature.' },
                    getItemSummary: (item: { title: string }) => item.title || 'Feature',
                },
            },
            defaultProps: {
                items: [
                    { icon: 'Users', title: 'Discover & connect', text: 'A community-first marketplace for events worth your time.' },
                    { icon: 'Ticket', title: 'Sell tickets', text: 'Multi-tier ticketing, seating and secure payments.' },
                    { icon: 'QrCode', title: 'Check them in', text: 'Fast QR entry passes at the door.' },
                ],
            },
        },
        FAQ: {
            ...renderComponents.FAQ,
            fields: {
                title: { type: 'text' },
                items: { type: 'array', arrayFields: { q: { type: 'text' }, a: { type: 'textarea' } }, defaultItemProps: { q: 'Question?', a: 'Answer.' }, getItemSummary: (item: { q: string }) => item.q || 'Question' },
            },
            defaultProps: { title: 'Frequently asked questions', items: [{ q: 'Where is the event?', a: 'The venue address is shown on your ticket.' }, { q: 'Can I get a refund?', a: 'Refunds are available up to 48 hours before the event.' }] },
        },
        CTA: {
            ...renderComponents.CTA,
            fields: { title: { type: 'text' }, text: { type: 'textarea' }, buttonText: { type: 'text' }, buttonUrl: { type: 'text' }, background: BG, bgImage: imageUpload, overlay: OVERLAY },
            defaultProps: { title: 'Ready to host your event?', text: 'Create your first event in minutes.', buttonText: 'Get started', buttonUrl: '/get-started', background: 'dark', bgImage: '', overlay: 50 },
        },
        Video: {
            ...renderComponents.Video,
            fields: { url: { type: 'text' } },
            defaultProps: { url: '' },
        },
        Testimonial: {
            ...renderComponents.Testimonial,
            fields: { quote: { type: 'textarea' }, author: { type: 'text' }, role: { type: 'text' } },
            defaultProps: { quote: 'DropRSVP made selling out our launch night effortless.', author: 'Aisyah R.', role: 'Event organizer' },
        },
        Stats: {
            ...renderComponents.Stats,
            fields: {
                items: { type: 'array', arrayFields: { value: { type: 'text' }, label: { type: 'text' } }, defaultItemProps: { value: '100+', label: 'Events' }, getItemSummary: (item: { label: string }) => item.label || 'Stat' },
            },
            defaultProps: { items: [{ value: '10k+', label: 'Tickets sold' }, { value: '500+', label: 'Events hosted' }, { value: '4.9★', label: 'Average rating' }] },
        },
        Steps: {
            ...renderComponents.Steps,
            fields: {
                title: { type: 'text' },
                items: { type: 'array', arrayFields: { title: { type: 'text' }, text: { type: 'textarea' } }, defaultItemProps: { title: 'Step', text: 'Describe this step.' }, getItemSummary: (item: { title: string }) => item.title || 'Step' },
            },
            defaultProps: { title: 'How it works', items: [{ title: 'Create', text: 'Set up your event and tickets in minutes.' }, { title: 'Share', text: 'Publish and promote to your audience.' }, { title: 'Check in', text: 'Scan QR passes at the door.' }] },
        },
        Logos: {
            ...renderComponents.Logos,
            fields: {
                title: { type: 'text' },
                images: { type: 'array', arrayFields: { image: imageUpload }, defaultItemProps: { image: '' }, getItemSummary: (_i, i) => `Logo ${(i ?? 0) + 1}` },
            },
            defaultProps: { title: 'Trusted by', images: [{ image: '' }, { image: '' }, { image: '' }] },
        },
        Posts: {
            ...renderComponents.Posts,
            fields: { heading: { type: 'text' }, limit: { type: 'number', min: 1, max: 12 } },
            defaultProps: { heading: 'Latest from the blog', limit: 6 },
        },
        Spacer: {
            ...renderComponents.Spacer,
            fields: { size: { type: 'select', options: [{ label: 'Small', value: 'sm' }, { label: 'Medium', value: 'md' }, { label: 'Large', value: 'lg' }, { label: 'Extra large', value: 'xl' }] } },
            defaultProps: { size: 'md' },
        },
        Divider: {
            ...renderComponents.Divider,
            fields: { width: { type: 'radio', options: [{ label: 'Narrow', value: 'narrow' }, { label: 'Wide', value: 'wide' }] } },
            defaultProps: { width: 'wide' },
        },
    },
};

/** Icon shown next to each widget in the builder's component drawer. */
export const COMPONENT_ICONS: Record<string, LucideIcon> = {
    Hero: Sparkles,
    Features: LayoutGrid,
    Steps: ListOrdered,
    CTA: Megaphone,
    FAQ: HelpCircle,
    Carousel: Images,
    Video: VideoIcon,
    Posts: Newspaper,
    Heading: Type,
    Text: Pilcrow,
    Button: MousePointerClick,
    Image: ImageIcon,
    Gallery: Images,
    Testimonial: Quote,
    Stats: BarChart3,
    Logos: Building2,
    Spacer: StretchVertical,
    Divider: Minus,
};

/** A brand-new page starts with a hero the author can edit straight away. */
export const emptyData: Data = {
    root: {},
    content: [
        { type: 'Hero', props: { id: 'hero-1', title: 'Your page title', subtitle: 'Add a short, welcoming introduction here.', buttonText: 'Browse events', buttonUrl: '/events', align: 'text-center', background: 'none', bgImage: '', overlay: 40 } },
    ],
} as Data;
