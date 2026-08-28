import { FieldLabel } from '@measured/puck';
import type { Config, CustomField, Data } from '@measured/puck';
import {
    BarChart3, Building2, CalendarDays, ChevronLeft, ChevronRight, HelpCircle, Image as ImageIcon, Images,
    ImageUp, LayoutGrid, Loader2, Megaphone, Minus, MousePointerClick, Pilcrow, QrCode, Quote, ListOrdered,
    Sparkles, StretchVertical, Ticket, Trash2, Type, Users, Video as VideoIcon,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import { uploadImage } from '@/lib/upload';

/**
 * The DropRSVP page-builder configuration.
 *
 * This is the SINGLE source of truth for every widget: the admin Puck editor
 * and the public <Render> both use it, so what you build is exactly what
 * visitors see. Every widget is a plain React component using the DropRSVP
 * Tailwind theme — so pages can never drift off-brand.
 */

const ICONS: Record<string, LucideIcon> = { Ticket, Users, QrCode, Calendar: CalendarDays };

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
const bgClass = (v: string) =>
    v === 'muted' ? 'bg-muted' : v === 'dark' ? 'bg-foreground text-background' : v === 'primary' ? 'bg-primary text-primary-foreground' : '';

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

/**
 * Section wrapper that supports a colour OR an uploaded background image with a
 * darkening overlay (so text stays readable). Returns whether content should be
 * rendered "on dark" for the caller to colour text/buttons.
 */
function SectionBg({ background, bgImage, overlay = 40, className = '', children }: { background: string; bgImage?: string; overlay?: number; className?: string; children: ReactNode }) {
    const hasImage = !!bgImage;

    return (
        <section
            className={`relative overflow-hidden ${hasImage ? 'text-white' : bgClass(background)} ${className}`}
            style={hasImage ? { backgroundImage: `url(${bgImage})`, backgroundSize: 'cover', backgroundPosition: 'center' } : undefined}
        >
            {hasImage && <div aria-hidden className="absolute inset-0 bg-black" style={{ opacity: overlay / 100 }} />}
            <div className="relative">{children}</div>
        </section>
    );
}

const onDarkBg = (background: string, bgImage?: string) => !!bgImage || background === 'dark' || background === 'primary';

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

/* ---- widget runtime components ----------------------------------------- */

function Carousel({ slides }: { slides: { image: string; caption: string }[] }) {
    'use no memo';
    const ref = useRef<HTMLDivElement>(null);
    const scroll = (dir: number) => ref.current?.scrollBy({ left: dir * ref.current.clientWidth, behavior: 'smooth' });
    const items = (slides ?? []).filter((s) => s.image);

    return (
        <div className="relative mx-auto max-w-5xl px-6 py-8">
            <div ref={ref} className="flex snap-x snap-mandatory gap-4 overflow-x-auto scroll-smooth rounded-2xl [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                {items.length ? (
                    items.map((s, i) => (
                        <figure key={i} className="relative w-full shrink-0 snap-center">
                            <img src={s.image} alt={s.caption} className="h-72 w-full rounded-2xl border border-border object-cover sm:h-96" />
                            {s.caption && <figcaption className="absolute inset-x-0 bottom-0 rounded-b-2xl bg-gradient-to-t from-black/70 to-transparent p-4 text-sm font-medium text-white">{s.caption}</figcaption>}
                        </figure>
                    ))
                ) : (
                    <div className="flex h-72 w-full items-center justify-center rounded-2xl border border-dashed border-border text-sm text-muted-foreground">Add slides in the panel →</div>
                )}
            </div>
            {items.length > 1 && (
                <>
                    <button type="button" onClick={() => scroll(-1)} aria-label="Previous" className="absolute left-8 top-1/2 flex size-9 -translate-y-1/2 items-center justify-center rounded-full border border-border bg-background/90 shadow"><ChevronLeft className="size-4" /></button>
                    <button type="button" onClick={() => scroll(1)} aria-label="Next" className="absolute right-8 top-1/2 flex size-9 -translate-y-1/2 items-center justify-center rounded-full border border-border bg-background/90 shadow"><ChevronRight className="size-4" /></button>
                </>
            )}
        </div>
    );
}

/** Convert a YouTube/Vimeo/other URL into an embeddable src. */
function embedSrc(url: string): string {
    if (!url) {
return '';
}

    const yt = url.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([\w-]{11})/);

    if (yt) {
return `https://www.youtube.com/embed/${yt[1]}`;
}

    const vimeo = url.match(/vimeo\.com\/(\d+)/);

    if (vimeo) {
return `https://player.vimeo.com/video/${vimeo[1]}`;
}

    return url;
}

/* ---- typed props ------------------------------------------------------- */

export type Props = {
    Hero: { title: string; subtitle: string; buttonText: string; buttonUrl: string; align: string; background: string; bgImage: string; overlay: number };
    Heading: { text: string; level: 'h1' | 'h2' | 'h3'; align: string };
    Text: { text: string; align: string };
    Button: { label: string; url: string; variant: 'solid' | 'outline'; align: string };
    Image: { url: string; alt: string; width: 'container' | 'full'; rounded: boolean };
    Gallery: { images: { image: string }[]; columns: '2' | '3' | '4' };
    Carousel: { slides: { image: string; caption: string }[] };
    Features: { items: { icon: string; title: string; text: string }[] };
    FAQ: { title: string; items: { q: string; a: string }[] };
    CTA: { title: string; text: string; buttonText: string; buttonUrl: string; background: string; bgImage: string; overlay: number };
    Video: { url: string };
    Testimonial: { quote: string; author: string; role: string };
    Stats: { items: { value: string; label: string }[] };
    Steps: { title: string; items: { title: string; text: string }[] };
    Logos: { title: string; images: { image: string }[] };
    Spacer: { size: 'sm' | 'md' | 'lg' | 'xl' };
    Divider: { width: 'narrow' | 'wide' };
};

/* ---- config ------------------------------------------------------------ */

export const config: Config<Props> = {
    categories: {
        sections: { title: 'Sections', components: ['Hero', 'Features', 'Steps', 'CTA', 'FAQ', 'Carousel', 'Video'] },
        content: { title: 'Content', components: ['Heading', 'Text', 'Button', 'Image', 'Gallery', 'Testimonial', 'Stats', 'Logos'] },
        layout: { title: 'Layout', components: ['Spacer', 'Divider'] },
    },
    components: {
        Hero: {
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
            render: ({ title, subtitle, buttonText, buttonUrl, align, background, bgImage, overlay }) => {
                const dark = onDarkBg(background, bgImage);

                return (
                    <SectionBg background={background} bgImage={bgImage} overlay={overlay}>
                        <div className={`px-6 py-20 ${align} ${align === 'text-center' ? 'mx-auto max-w-3xl' : 'mx-auto max-w-5xl'}`}>
                            <h1 className="text-4xl font-bold leading-tight tracking-tight sm:text-5xl">{title}</h1>
                            {subtitle && <p className={`mt-5 max-w-xl text-lg ${align === 'text-center' ? 'mx-auto' : ''} ${dark ? 'opacity-90' : 'text-muted-foreground'}`}>{subtitle}</p>}
                            {buttonText && <a href={buttonUrl} className={`mt-8 inline-flex rounded-full px-7 py-3 text-sm font-semibold ${dark ? 'bg-background text-foreground' : 'bg-primary text-primary-foreground'}`}>{buttonText}</a>}
                        </div>
                    </SectionBg>
                );
            },
        },
        Heading: {
            fields: { text: { type: 'text' }, level: { type: 'radio', options: [{ label: 'H1', value: 'h1' }, { label: 'H2', value: 'h2' }, { label: 'H3', value: 'h3' }] }, align: ALIGN },
            defaultProps: { text: 'Section heading', level: 'h2', align: 'text-left' },
            render: ({ text, level, align }) => {
                const cls = `mx-auto max-w-4xl px-6 pt-8 font-bold tracking-tight ${align} ${level === 'h1' ? 'text-4xl' : level === 'h2' ? 'text-3xl' : 'text-xl'}`;
                const Tag = level;

                return <Tag className={cls}>{text}</Tag>;
            },
        },
        Text: {
            fields: { text: { type: 'textarea' }, align: ALIGN },
            defaultProps: { text: 'Write a paragraph about your event, venue or organisation here.', align: 'text-left' },
            render: ({ text, align }) => <p className={`mx-auto max-w-3xl px-6 py-3 leading-relaxed text-foreground/90 ${align}`}>{text}</p>,
        },
        Button: {
            fields: { label: { type: 'text' }, url: { type: 'text' }, variant: { type: 'radio', options: [{ label: 'Solid', value: 'solid' }, { label: 'Outline', value: 'outline' }] }, align: ALIGN },
            defaultProps: { label: 'Get tickets', url: '/events', variant: 'solid', align: 'text-left' },
            render: ({ label, url, variant, align }) => (
                <div className={`mx-auto max-w-3xl px-6 py-3 ${align}`}>
                    <a href={url} className={`inline-flex rounded-full px-6 py-2.5 text-sm font-semibold ${variant === 'outline' ? 'border border-foreground text-foreground' : 'bg-primary text-primary-foreground'}`}>{label}</a>
                </div>
            ),
        },
        Image: {
            fields: {
                url: imageUpload,
                alt: { type: 'text' },
                width: { type: 'radio', options: [{ label: 'Container', value: 'container' }, { label: 'Full width', value: 'full' }] },
                rounded: { type: 'radio', options: [{ label: 'Rounded', value: true }, { label: 'Square', value: false }] },
            },
            defaultProps: { url: '', alt: '', width: 'container', rounded: true },
            render: ({ url, alt, width, rounded }) => (
                <div className={width === 'full' ? 'py-4' : 'mx-auto max-w-4xl px-6 py-4'}>
                    {url ? (
                        <img src={url} alt={alt} className={`w-full object-cover ${rounded ? 'rounded-2xl' : ''} ${width === 'container' ? 'border border-border' : ''}`} />
                    ) : (
                        <div className="mx-auto flex h-48 max-w-4xl items-center justify-center rounded-2xl border border-dashed border-border text-sm text-muted-foreground">Upload an image in the panel →</div>
                    )}
                </div>
            ),
        },
        Gallery: {
            fields: {
                images: { type: 'array', arrayFields: { image: imageUpload }, defaultItemProps: { image: '' }, getItemSummary: (_i, i) => `Image ${(i ?? 0) + 1}` },
                columns: { type: 'radio', options: [{ label: '2', value: '2' }, { label: '3', value: '3' }, { label: '4', value: '4' }] },
            },
            defaultProps: { images: [{ image: '' }, { image: '' }], columns: '3' },
            render: ({ images, columns }) => {
                const cols = columns === '2' ? 'sm:grid-cols-2' : columns === '4' ? 'sm:grid-cols-4' : 'sm:grid-cols-3';
                const items = (images ?? []).filter((x) => x.image);

                return (
                    <div className={`mx-auto grid max-w-6xl grid-cols-2 gap-3 px-6 py-6 ${cols}`}>
                        {items.length ? (
                            items.map((x, i) => <img key={i} src={x.image} alt="" className="aspect-square w-full rounded-xl border border-border object-cover" />)
                        ) : (
                            <div className="col-span-full flex h-32 items-center justify-center rounded-xl border border-dashed border-border text-sm text-muted-foreground">Add images in the panel →</div>
                        )}
                    </div>
                );
            },
        },
        Carousel: {
            fields: {
                slides: { type: 'array', arrayFields: { image: imageUpload, caption: { type: 'text' } }, defaultItemProps: { image: '', caption: '' }, getItemSummary: (item: { caption: string }, i) => item.caption || `Slide ${(i ?? 0) + 1}` },
            },
            defaultProps: { slides: [{ image: '', caption: '' }] },
            render: ({ slides }) => <Carousel slides={slides} />,
        },
        Features: {
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
            render: ({ items }) => (
                <div className="mx-auto grid max-w-6xl gap-6 px-6 py-10 sm:grid-cols-3">
                    {(items ?? []).map((f, i) => {
                        const Icon = ICONS[f.icon] ?? Ticket;

                        return (
                            <div key={i} className="rounded-2xl border border-border bg-card p-6 shadow-sm">
                                <span className="flex size-11 items-center justify-center rounded-xl bg-foreground text-background"><Icon className="size-5" /></span>
                                <h3 className="mt-4 text-lg font-semibold">{f.title}</h3>
                                <p className="mt-2 text-sm text-muted-foreground">{f.text}</p>
                            </div>
                        );
                    })}
                </div>
            ),
        },
        FAQ: {
            fields: {
                title: { type: 'text' },
                items: { type: 'array', arrayFields: { q: { type: 'text' }, a: { type: 'textarea' } }, defaultItemProps: { q: 'Question?', a: 'Answer.' }, getItemSummary: (item: { q: string }) => item.q || 'Question' },
            },
            defaultProps: { title: 'Frequently asked questions', items: [{ q: 'Where is the event?', a: 'The venue address is shown on your ticket.' }, { q: 'Can I get a refund?', a: 'Refunds are available up to 48 hours before the event.' }] },
            render: ({ title, items }) => (
                <section className="mx-auto max-w-3xl px-6 py-12">
                    {title && <h2 className="text-2xl font-bold tracking-tight">{title}</h2>}
                    <div className="mt-6 divide-y divide-border rounded-2xl border border-border">
                        {(items ?? []).map((f, i) => (
                            <details key={i} className="group px-5 py-4 [&_summary]:cursor-pointer">
                                <summary className="flex items-center justify-between font-medium marker:content-none">
                                    {f.q}
                                    <ChevronRight className="size-4 transition-transform group-open:rotate-90" />
                                </summary>
                                <p className="mt-3 text-sm leading-relaxed text-muted-foreground">{f.a}</p>
                            </details>
                        ))}
                    </div>
                </section>
            ),
        },
        CTA: {
            fields: { title: { type: 'text' }, text: { type: 'textarea' }, buttonText: { type: 'text' }, buttonUrl: { type: 'text' }, background: BG, bgImage: imageUpload, overlay: OVERLAY },
            defaultProps: { title: 'Ready to host your event?', text: 'Create your first event in minutes.', buttonText: 'Get started', buttonUrl: '/get-started', background: 'dark', bgImage: '', overlay: 50 },
            render: ({ title, text, buttonText, buttonUrl, background, bgImage, overlay }) => {
                const dark = onDarkBg(background, bgImage);

                return (
                    <SectionBg background={background === 'none' && !bgImage ? 'muted' : background} bgImage={bgImage} overlay={overlay} className="px-6 py-16">
                        <div className="mx-auto max-w-3xl text-center">
                            <h2 className="text-3xl font-bold tracking-tight">{title}</h2>
                            {text && <p className={`mt-3 ${dark ? 'opacity-90' : 'text-muted-foreground'}`}>{text}</p>}
                            {buttonText && <a href={buttonUrl} className={`mt-7 inline-flex rounded-full px-7 py-3 text-sm font-semibold ${dark ? 'bg-background text-foreground' : 'bg-primary text-primary-foreground'}`}>{buttonText}</a>}
                        </div>
                    </SectionBg>
                );
            },
        },
        Video: {
            fields: { url: { type: 'text' } },
            defaultProps: { url: '' },
            render: ({ url }) => {
                const src = embedSrc(url);

                return (
                    <div className="mx-auto max-w-4xl px-6 py-6">
                        {src ? (
                            <div className="aspect-video overflow-hidden rounded-2xl border border-border">
                                <iframe src={src} title="Video" className="h-full w-full" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowFullScreen />
                            </div>
                        ) : (
                            <div className="flex aspect-video items-center justify-center rounded-2xl border border-dashed border-border text-sm text-muted-foreground">Paste a YouTube or Vimeo URL in the panel →</div>
                        )}
                    </div>
                );
            },
        },
        Testimonial: {
            fields: { quote: { type: 'textarea' }, author: { type: 'text' }, role: { type: 'text' } },
            defaultProps: { quote: 'DropRSVP made selling out our launch night effortless.', author: 'Aisyah R.', role: 'Event organizer' },
            render: ({ quote, author, role }) => (
                <figure className="mx-auto max-w-3xl px-6 py-12 text-center">
                    <Quote className="mx-auto size-8 text-primary" />
                    <blockquote className="mt-4 text-xl font-medium leading-relaxed text-foreground">“{quote}”</blockquote>
                    <figcaption className="mt-4 text-sm text-muted-foreground"><span className="font-semibold text-foreground">{author}</span>{role ? ` · ${role}` : ''}</figcaption>
                </figure>
            ),
        },
        Stats: {
            fields: {
                items: { type: 'array', arrayFields: { value: { type: 'text' }, label: { type: 'text' } }, defaultItemProps: { value: '100+', label: 'Events' }, getItemSummary: (item: { label: string }) => item.label || 'Stat' },
            },
            defaultProps: { items: [{ value: '10k+', label: 'Tickets sold' }, { value: '500+', label: 'Events hosted' }, { value: '4.9★', label: 'Average rating' }] },
            render: ({ items }) => (
                <div className="mx-auto grid max-w-4xl grid-cols-2 gap-6 px-6 py-12 sm:grid-cols-3">
                    {(items ?? []).map((s, i) => (
                        <div key={i} className="text-center">
                            <div className="text-3xl font-bold tracking-tight sm:text-4xl">{s.value}</div>
                            <div className="mt-1 text-sm text-muted-foreground">{s.label}</div>
                        </div>
                    ))}
                </div>
            ),
        },
        Steps: {
            fields: {
                title: { type: 'text' },
                items: { type: 'array', arrayFields: { title: { type: 'text' }, text: { type: 'textarea' } }, defaultItemProps: { title: 'Step', text: 'Describe this step.' }, getItemSummary: (item: { title: string }) => item.title || 'Step' },
            },
            defaultProps: { title: 'How it works', items: [{ title: 'Create', text: 'Set up your event and tickets in minutes.' }, { title: 'Share', text: 'Publish and promote to your audience.' }, { title: 'Check in', text: 'Scan QR passes at the door.' }] },
            render: ({ title, items }) => (
                <section className="mx-auto max-w-5xl px-6 py-12">
                    {title && <h2 className="mb-8 text-center text-2xl font-bold tracking-tight sm:text-3xl">{title}</h2>}
                    <ol className="grid gap-6 sm:grid-cols-3">
                        {(items ?? []).map((s, i) => (
                            <li key={i} className="rounded-2xl border border-border bg-card p-6">
                                <span className="flex size-9 items-center justify-center rounded-full bg-primary text-sm font-bold text-primary-foreground">{i + 1}</span>
                                <h3 className="mt-4 font-semibold">{s.title}</h3>
                                <p className="mt-1 text-sm text-muted-foreground">{s.text}</p>
                            </li>
                        ))}
                    </ol>
                </section>
            ),
        },
        Logos: {
            fields: {
                title: { type: 'text' },
                images: { type: 'array', arrayFields: { image: imageUpload }, defaultItemProps: { image: '' }, getItemSummary: (_i, i) => `Logo ${(i ?? 0) + 1}` },
            },
            defaultProps: { title: 'Trusted by', images: [{ image: '' }, { image: '' }, { image: '' }] },
            render: ({ title, images }) => {
                const items = (images ?? []).filter((x) => x.image);

                return (
                    <section className="mx-auto max-w-5xl px-6 py-10 text-center">
                        {title && <p className="mb-6 text-xs font-semibold uppercase tracking-wider text-muted-foreground">{title}</p>}
                        {items.length ? (
                            <div className="flex flex-wrap items-center justify-center gap-x-10 gap-y-6">
                                {items.map((x, i) => <img key={i} src={x.image} alt="" className="h-8 w-auto opacity-70 grayscale transition hover:opacity-100 hover:grayscale-0" />)}
                            </div>
                        ) : <div className="flex h-16 items-center justify-center rounded-xl border border-dashed border-border text-sm text-muted-foreground">Add logos in the panel →</div>}
                    </section>
                );
            },
        },
        Spacer: {
            fields: { size: { type: 'select', options: [{ label: 'Small', value: 'sm' }, { label: 'Medium', value: 'md' }, { label: 'Large', value: 'lg' }, { label: 'Extra large', value: 'xl' }] } },
            defaultProps: { size: 'md' },
            render: ({ size }) => <div className={size === 'sm' ? 'h-6' : size === 'lg' ? 'h-20' : size === 'xl' ? 'h-32' : 'h-12'} />,
        },
        Divider: {
            fields: { width: { type: 'radio', options: [{ label: 'Narrow', value: 'narrow' }, { label: 'Wide', value: 'wide' }] } },
            defaultProps: { width: 'wide' },
            render: ({ width }) => <hr className={`mx-auto my-6 border-border ${width === 'narrow' ? 'max-w-3xl' : 'max-w-6xl'} px-6`} />,
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
