import type { Config } from '@measured/puck';
import {
    CalendarDays, ChevronLeft, ChevronRight, Newspaper, QrCode, Quote, Ticket, Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { useRef } from 'react';

/**
 * The DropRSVP page-builder RENDER side.
 *
 * This module holds ONLY what `<Render>` needs to paint a page: the widget
 * render functions plus their shared helpers. It deliberately imports none of
 * the editor-only field code (image uploader, FieldLabel, toast, uploadImage),
 * so public pages and the page-form preview never pull that heavy chunk. The
 * admin builder attaches `fields`/`defaultProps` on top of these renders in
 * `puck-config.tsx`.
 */

const ICONS: Record<string, LucideIcon> = { Ticket, Users, QrCode, Calendar: CalendarDays };

const bgClass = (v: string) =>
    v === 'muted' ? 'bg-muted' : v === 'dark' ? 'bg-foreground text-background' : v === 'primary' ? 'bg-primary text-primary-foreground' : '';

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
    Posts: { heading: string; limit: number };
    Spacer: { size: 'sm' | 'md' | 'lg' | 'xl' };
    Divider: { width: 'narrow' | 'wide' };
};

/** A blog-post card, provided to the Posts widget via Puck metadata. */
export interface PostCard { title: string; slug: string; excerpt: string; cover_image: string | null; category: string | null; date: string | null }

/* ---- render-only components -------------------------------------------- */

export const renderComponents: Config<Props>['components'] = {
    Hero: {
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
        render: ({ text, level, align }) => {
            const cls = `mx-auto max-w-4xl px-6 pt-8 font-bold tracking-tight ${align} ${level === 'h1' ? 'text-4xl' : level === 'h2' ? 'text-3xl' : 'text-xl'}`;
            const Tag = level;

            return <Tag className={cls}>{text}</Tag>;
        },
    },
    Text: {
        render: ({ text, align }) => <p className={`mx-auto max-w-3xl px-6 py-3 leading-relaxed text-foreground/90 ${align}`}>{text}</p>,
    },
    Button: {
        render: ({ label, url, variant, align }) => (
            <div className={`mx-auto max-w-3xl px-6 py-3 ${align}`}>
                <a href={url} className={`inline-flex rounded-full px-6 py-2.5 text-sm font-semibold ${variant === 'outline' ? 'border border-foreground text-foreground' : 'bg-primary text-primary-foreground'}`}>{label}</a>
            </div>
        ),
    },
    Image: {
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
        render: ({ slides }) => <Carousel slides={slides} />,
    },
    Features: {
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
        render: ({ quote, author, role }) => (
            <figure className="mx-auto max-w-3xl px-6 py-12 text-center">
                <Quote className="mx-auto size-8 text-primary" />
                <blockquote className="mt-4 text-xl font-medium leading-relaxed text-foreground">“{quote}”</blockquote>
                <figcaption className="mt-4 text-sm text-muted-foreground"><span className="font-semibold text-foreground">{author}</span>{role ? ` · ${role}` : ''}</figcaption>
            </figure>
        ),
    },
    Stats: {
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
    Posts: {
        render: ({ heading, limit, puck }) => {
            const posts = ((puck?.metadata?.posts as PostCard[] | undefined) ?? []).slice(0, limit || 6);

            if (posts.length === 0) {
                return (
                    <section className="mx-auto max-w-6xl px-6 py-12">
                        {heading && <h2 className="mb-6 text-2xl font-bold tracking-tight sm:text-3xl">{heading}</h2>}
                        <div className="flex h-40 items-center justify-center rounded-2xl border border-dashed border-border text-sm text-muted-foreground">
                            Your published blog posts will appear here automatically.
                        </div>
                    </section>
                );
            }

            return (
                <section className="mx-auto max-w-6xl px-6 py-12">
                    {heading && <h2 className="mb-6 text-2xl font-bold tracking-tight sm:text-3xl">{heading}</h2>}
                    <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                        {posts.map((p) => (
                            <a key={p.slug} href={`/blog/${p.slug}`} className="group flex h-full flex-col overflow-hidden rounded-2xl border border-border bg-card shadow-sm transition-all hover:-translate-y-1 hover:shadow-md">
                                <div className="aspect-[16/10] overflow-hidden bg-muted">
                                    {p.cover_image
                                        ? <img src={p.cover_image} alt={p.title} loading="lazy" className="size-full object-cover transition-transform duration-500 group-hover:scale-105" />
                                        : <div className="flex size-full items-center justify-center text-muted-foreground"><Newspaper className="size-8" /></div>}
                                </div>
                                <div className="flex flex-1 flex-col p-5">
                                    <div className="mb-2 flex items-center gap-2 text-xs text-muted-foreground">
                                        {p.category && <span className="rounded-full bg-secondary px-2 py-0.5 font-medium text-foreground">{p.category}</span>}
                                        {p.date && <span>{p.date}</span>}
                                    </div>
                                    <h3 className="font-semibold leading-snug group-hover:underline">{p.title}</h3>
                                    {p.excerpt && <p className="mt-2 line-clamp-2 text-sm text-muted-foreground">{p.excerpt}</p>}
                                </div>
                            </a>
                        ))}
                    </div>
                </section>
            );
        },
    },
    Spacer: {
        render: ({ size }) => <div className={size === 'sm' ? 'h-6' : size === 'lg' ? 'h-20' : size === 'xl' ? 'h-32' : 'h-12'} />,
    },
    Divider: {
        render: ({ width }) => <hr className={`mx-auto my-6 border-border ${width === 'narrow' ? 'max-w-3xl' : 'max-w-6xl'} px-6`} />,
    },
};

/** A components-only config for `<Render>` — no editor field code attached. */
export const renderConfig: Config<Props> = { components: renderComponents };
