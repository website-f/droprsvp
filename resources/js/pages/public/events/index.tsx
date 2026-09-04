import { Head, Link, router } from '@inertiajs/react';
import { CalendarDays, ChevronRight, MapPin, Rocket, Star } from 'lucide-react';
import { useEffect, useState } from 'react';
import { PublicFooter, PublicHeader } from '@/components/public-header';
import { contentClass } from '@/components/rich-editor';
import { SearchAutocomplete } from '@/components/search-autocomplete';
import { AppSelect } from '@/components/ui/app-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

interface Card { slug: string; title: string; cover_image: string | null; category: string | null; city: string | null; boosted?: boolean; when: string | null; venue: string | null; from_price: number | null; has_free: boolean; participants: number; faces: string[]; rating: number | null; rating_count: number }
interface Featured { slug: string; title: string; subtitle: string | null; banner_image: string; category: string | null; city: string | null; when: string | null; venue: string | null; url: string }
interface Hero { enabled: boolean; heading: string; subheading: string; image: string; cta_label: string; cta_url: string; align: 'left' | 'center' | 'right' }
interface SeoText { enabled: boolean; heading: string; body: string }
interface Crumb { name: string; url: string }

const AVATAR_TINTS = ['#6c63ff', '#2ec4b6', '#f5a524', '#ff6584', '#3b82f6'];
const initials = (name: string) => name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]?.toUpperCase()).join('') || '?';
interface Paginated { data: Card[]; prev_page_url: string | null; next_page_url: string | null }
interface Category { name: string; slug: string }
interface City { name: string; slug: string }
interface Active { city: string | null; city_name: string | null; category: string | null; category_name: string | null }
interface CategoryContent { name: string; content: string }
interface Seo { title: string }

/** SEO copy block shown at the bottom of the page, truncated with a See more toggle. */
function ContentBlock({ name, content }: CategoryContent) {
    const [open, setOpen] = useState(false);
    const long = content.length > 320;

    return (
        <div className="rounded-2xl border border-border bg-card p-5">
            <h3 className="mb-2 font-semibold">{name}</h3>
            <p className={`whitespace-pre-line text-sm leading-relaxed text-muted-foreground ${!open && long ? 'line-clamp-4' : ''}`}>{content}</p>
            {long && (
                <button type="button" onClick={() => setOpen((v) => !v)} className="mt-2 text-sm font-medium text-foreground underline underline-offset-4">
                    {open ? 'See less' : 'See more'}
                </button>
            )}
        </div>
    );
}

/** Slide shape shared by the admin default banner + featured organizer events. */
type Slide =
    | { kind: 'admin'; image: string; heading: string; subheading: string; cta_label: string; cta_url: string; align: 'left' | 'center' | 'right' }
    | { kind: 'event'; image: string; title: string; subtitle: string | null; category: string | null; city: string | null; when: string | null; venue: string | null; url: string };

/** Rotating hero at the top of the events page — admin banner + featured organizer banners. */
function EventsHero({ slides }: { slides: Slide[] }) {
    const [i, setI] = useState(0);
    const count = slides.length;

    useEffect(() => {
        if (count < 2) {
            return;
        }

        const id = setInterval(() => setI((v) => (v + 1) % count), 6000);

        return () => clearInterval(id);
    }, [count]);

    if (count === 0) {
        return null;
    }

    const cur = Math.min(i, count - 1);
    const alignFor = (s: Slide) => (s.kind === 'admin' ? s.align : 'left');

    return (
        <div className="relative overflow-hidden rounded-3xl border border-border bg-muted shadow-sm">
            <div className="relative aspect-[3/1] w-full min-h-[220px]">
                {slides.map((s, idx) => {
                    const align = alignFor(s);
                    const inner = (
                        <>
                            {s.image
                                ? <img src={s.image} alt="" className="absolute inset-0 size-full object-cover" />
                                : <div className="absolute inset-0 bg-gradient-to-br from-[#6c63ff] to-[#2ec4b6]" />}
                            <div className="absolute inset-0 bg-gradient-to-t from-black/75 via-black/30 to-black/10" />
                            <div className={`absolute inset-0 flex flex-col justify-end gap-2 p-6 text-white sm:p-10 ${align === 'center' ? 'items-center text-center' : align === 'right' ? 'items-end text-right' : 'items-start text-left'}`}>
                                {s.kind === 'event' && s.category && <span className="inline-flex w-max rounded-full bg-white/20 px-3 py-0.5 text-xs font-semibold backdrop-blur">{s.category}</span>}
                                <h2 className="max-w-2xl text-2xl font-bold leading-tight drop-shadow sm:text-4xl">{s.kind === 'admin' ? s.heading : s.title}</h2>
                                {(s.kind === 'admin' ? s.subheading : s.subtitle) && (
                                    <p className="max-w-xl text-sm text-white/90 drop-shadow sm:text-base">{s.kind === 'admin' ? s.subheading : s.subtitle}</p>
                                )}
                                {s.kind === 'event' && (
                                    <div className={`mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-white/85 sm:text-sm ${align === 'center' ? 'justify-center' : align === 'right' ? 'justify-end' : ''}`}>
                                        {s.when && <span className="flex items-center gap-1.5"><CalendarDays className="size-4" /> {s.when}</span>}
                                        {(s.venue || s.city) && <span className="flex items-center gap-1.5"><MapPin className="size-4" /> {[s.venue, s.city].filter(Boolean).join(' · ')}</span>}
                                    </div>
                                )}
                                {s.kind === 'admin' && s.cta_label && s.cta_url && (
                                    <Button asChild size="sm" className="mt-2 w-max"><Link href={s.cta_url}>{s.cta_label}</Link></Button>
                                )}
                                {s.kind === 'event' && <span className="mt-2 inline-flex w-max items-center gap-1 rounded-full bg-white px-4 py-1.5 text-sm font-semibold text-black">View event <ChevronRight className="size-4" /></span>}
                            </div>
                        </>
                    );

                    return (
                        <div key={idx} className={`absolute inset-0 transition-opacity duration-700 ${idx === cur ? 'opacity-100' : 'pointer-events-none opacity-0'}`}>
                            {s.kind === 'event'
                                ? <Link href={s.url} className="block size-full">{inner}</Link>
                                : <div className="size-full">{inner}</div>}
                        </div>
                    );
                })}
            </div>

            {/* Slide dots — small, translucent "liquid glass" pills */}
            {count > 1 && (
                <div className="absolute bottom-3 left-1/2 flex -translate-x-1/2 gap-1.5 rounded-full bg-white/15 px-2 py-1 backdrop-blur-md">
                    {slides.map((_, idx) => (
                        <button
                            key={idx}
                            type="button"
                            aria-label={`Go to slide ${idx + 1}`}
                            onClick={() => setI(idx)}
                            className={`h-1.5 rounded-full transition-all ${idx === cur ? 'w-5 bg-white' : 'w-1.5 bg-white/50 hover:bg-white/80'}`}
                        />
                    ))}
                </div>
            )}
        </div>
    );
}

/** Foot-of-page SEO text block (admin rich HTML), collapsed to a teaser with Read more. */
function SeoBlock({ heading, body }: { heading: string; body: string }) {
    const [open, setOpen] = useState(false);

    return (
        <section className="mt-14 border-t border-border pt-10">
            <div className="mx-auto w-full max-w-4xl">
                {heading && <h2 className="text-xl font-bold tracking-tight">{heading}</h2>}
                <div className={`relative mt-3 ${open ? '' : 'max-h-28 overflow-hidden'}`}>
                    <div className={contentClass} dangerouslySetInnerHTML={{ __html: body }} />
                    {!open && <div className="pointer-events-none absolute inset-x-0 bottom-0 h-14 bg-gradient-to-t from-background to-transparent" />}
                </div>
                <button type="button" onClick={() => setOpen((v) => !v)} className="mt-2 text-sm font-semibold text-primary hover:underline">{open ? 'Show less' : 'Read more'}</button>
            </div>
        </section>
    );
}

const LOCALE = 'en-my';
const ANY = 'all';

/** Build a discovery path: /en-my, /en-my/{city}, or /en-my/{city|all}/{category}. */
function pathFor(citySlug: string | null, catSlug: string | null): string {
    const segs = [LOCALE];

    if (catSlug) {
        segs.push(citySlug || ANY, catSlug);
    } else if (citySlug) {
        segs.push(citySlug);
    } else {
        segs.push(ANY); // /en-my/all = browse everything (/en-my is the home)
    }

    return '/' + segs.join('/');
}

function priceLabel(c: Card): string {
    if (c.from_price !== null) {
return `From RM ${c.from_price.toFixed(2)}`;
}

    if (c.has_free) {
return 'Free';
}

    return '';
}

export default function Discover({ events, categories, cities, active, filters, seo, categoryContent = [], hero, featured = [], seoText, breadcrumbs = [] }: { events: Paginated; categories: Category[]; cities: City[]; active: Active; filters: { q: string; when: string }; seo: Seo; categoryContent?: CategoryContent[]; hero?: Hero; featured?: Featured[]; seoText?: SeoText; breadcrumbs?: Crumb[] }) {
    const goto = (path: string, keepQuery = true) =>
        router.get(path, keepQuery && filters.q ? { q: filters.q } : {}, { preserveScroll: true });

    const onCity = (slug: string) => goto(pathFor(slug === ANY ? null : slug, active.category));
    const onCategory = (slug: string | null) => goto(pathFor(active.city, slug));

    // Build hero slides: admin banner first (if enabled + has a heading or image),
    // then each featured organizer event that uploaded a banner.
    const slides: Slide[] = [];

    if (hero?.enabled && (hero.image || hero.heading)) {
        slides.push({ kind: 'admin', image: hero.image, heading: hero.heading, subheading: hero.subheading, cta_label: hero.cta_label, cta_url: hero.cta_url, align: hero.align });
    }

    for (const f of featured) {
        slides.push({ kind: 'event', image: f.banner_image, title: f.title, subtitle: f.subtitle, category: f.category, city: f.city, when: f.when, venue: f.venue, url: f.url });
    }

    return (
        <>
            {/* SEO is server-rendered by Laravel; keep only the tab <title>. */}
            <Head title={seo.title} />

            <div className="flex min-h-screen flex-col bg-background text-foreground">
                <PublicHeader />

                <main className="mx-auto w-full max-w-6xl flex-1 px-6 py-10">
                    {/* Breadcrumb */}
                    {breadcrumbs.length > 0 && (
                        <nav aria-label="Breadcrumb" className="mb-4 flex flex-wrap items-center gap-1 text-sm text-muted-foreground">
                            {breadcrumbs.map((c, idx) => (
                                <span key={c.url} className="flex items-center gap-1">
                                    {idx > 0 && <ChevronRight className="size-3.5 opacity-60" />}
                                    {idx < breadcrumbs.length - 1
                                        ? <Link href={c.url} className="hover:text-foreground hover:underline">{c.name}</Link>
                                        : <span className="font-medium text-foreground">{c.name}</span>}
                                </span>
                            ))}
                        </nav>
                    )}

                    {/* Hero carousel */}
                    {slides.length > 0 && <div className="mb-8"><EventsHero slides={slides} /></div>}

                    <h1 className="text-3xl font-bold tracking-tight">{seo.title}</h1>

                    {/* Search + city */}
                    <div className="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center">
                        <SearchAutocomplete
                            variant="compact"
                            initial={filters.q}
                            placeholder="Search events"
                            onSubmit={(term) => router.get(pathFor(active.city, active.category), term ? { q: term } : {}, { preserveScroll: true })}
                        />
                        <div className="sm:w-56">
                            <AppSelect
                                aria-label="City"
                                value={active.city ?? ANY}
                                onChange={onCity}
                                options={[{ value: ANY, label: 'All cities' }, ...cities.map((c) => ({ value: c.slug, label: c.name }))]}
                            />
                        </div>
                    </div>

                    {/* Category filter — links to SEO paths */}
                    <div className="mt-5 flex flex-wrap gap-2">
                        <button onClick={() => onCategory(null)} className={`rounded-full border px-4 py-1.5 text-sm transition-colors ${active.category === null ? 'border-foreground bg-foreground text-background' : 'border-border hover:border-foreground/40'}`}>All</button>
                        {categories.map((c) => (
                            <button key={c.slug} onClick={() => onCategory(c.slug)} className={`rounded-full border px-4 py-1.5 text-sm transition-colors ${active.category === c.slug ? 'border-foreground bg-foreground text-background' : 'border-border hover:border-foreground/40'}`}>{c.name}</button>
                        ))}
                    </div>

                    {/* Results */}
                    {events.data.length === 0 ? (
                        <p className="mt-12 text-center text-sm text-muted-foreground">No events found. Try a different city, category or search.</p>
                    ) : (
                        <div className="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                            {events.data.map((e) => (
                                <Link key={e.slug} href={`/en-my/e/${e.slug}`} className="group overflow-hidden rounded-2xl border border-border bg-card shadow-sm transition-colors hover:border-foreground/30">
                                    <div className="relative aspect-[16/9] w-full overflow-hidden bg-muted">
                                        {e.cover_image
                                            ? <img src={e.cover_image} alt={e.title} className="size-full object-cover" />
                                            : <div className="flex size-full items-center justify-center text-muted-foreground"><CalendarDays className="size-8" /></div>}
                                        {e.boosted && <span className="absolute left-2 top-2 inline-flex items-center gap-1 rounded-full bg-[#6c63ff] px-2 py-0.5 text-[11px] font-semibold text-white shadow"><Rocket className="size-3" /> Promoted</span>}
                                    </div>
                                    <div className="p-4">
                                        {e.category && <Badge variant="secondary" className="mb-2">{e.category}</Badge>}
                                        <h2 className="line-clamp-2 font-semibold leading-snug group-hover:underline">{e.title}</h2>
                                        <div className="mt-2 grid gap-1 text-xs text-muted-foreground">
                                            {e.when && <span className="flex items-center gap-1.5"><CalendarDays className="size-3.5" /> {e.when}</span>}
                                            {(e.venue || e.city) && <span className="flex items-center gap-1.5"><MapPin className="size-3.5" /> {[e.venue, e.city].filter(Boolean).join(' · ')}</span>}
                                        </div>
                                        {priceLabel(e) && <div className="mt-3 text-sm font-semibold">{priceLabel(e)}</div>}

                                        {/* Participants (stacked avatars + total) + rating */}
                                        {(e.participants > 0 || e.rating !== null) && (
                                            <div className="mt-3 flex items-center justify-between gap-3 border-t border-border/60 pt-3 text-xs text-muted-foreground">
                                                {e.participants > 0 ? (
                                                    <span className="flex items-center gap-2">
                                                        <span className="flex -space-x-2">
                                                            {e.faces.map((name, i) => (
                                                                <span key={i} title={name} className="flex size-6 items-center justify-center rounded-full text-[9px] font-bold text-white ring-2 ring-card" style={{ backgroundColor: AVATAR_TINTS[i % AVATAR_TINTS.length] }}>{initials(name)}</span>
                                                            ))}
                                                            <span className="flex size-6 items-center justify-center rounded-full bg-foreground px-1 text-[9px] font-bold text-background ring-2 ring-card">{e.participants > 99 ? '99+' : e.participants}</span>
                                                        </span>
                                                        going
                                                    </span>
                                                ) : <span />}
                                                {e.rating !== null && (
                                                    <span className="flex items-center gap-1"><Star className="size-3.5 fill-[#f5a524] text-[#f5a524]" /> {e.rating.toFixed(1)}<span className="text-muted-foreground/70">({e.rating_count})</span></span>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                </Link>
                            ))}
                        </div>
                    )}

                    {(events.prev_page_url || events.next_page_url) && (
                        <div className="mt-10 flex justify-between">
                            <Button asChild variant="outline" disabled={!events.prev_page_url}>{events.prev_page_url ? <Link href={events.prev_page_url}>← Previous</Link> : <span>← Previous</span>}</Button>
                            <Button asChild variant="outline" disabled={!events.next_page_url}>{events.next_page_url ? <Link href={events.next_page_url}>Next →</Link> : <span>Next →</span>}</Button>
                        </div>
                    )}

                    {/* Admin-authored SEO content, per category */}
                    {categoryContent.length > 0 && (
                        <div className="mt-14 grid gap-4 border-t border-border pt-10">
                            {categoryContent.map((c) => <ContentBlock key={c.name} name={c.name} content={c.content} />)}
                        </div>
                    )}

                    {/* Admin-authored events-page SEO text block (rich HTML) */}
                    {seoText?.enabled && seoText.body && <SeoBlock heading={seoText.heading} body={seoText.body} />}
                </main>

                <PublicFooter />
            </div>
        </>
    );
}
