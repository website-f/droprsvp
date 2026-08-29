import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowRight, CalendarDays, MapPin, Search, Sparkles } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { CategoryGrid } from '@/components/landing/category-grid';
import { HeroArt } from '@/components/landing/hero-art';
import { PublicFooter, PublicHeader } from '@/components/public-header';
import { Reveal } from '@/components/reveal';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';

interface FeaturedEvent {
    slug: string;
    title: string;
    cover_image: string | null;
    category: string | null;
    when: string | null;
    venue: string | null;
    from_price: number | null;
    has_free: boolean;
}

const money = (n: number) => new Intl.NumberFormat('en-MY', { style: 'currency', currency: 'MYR', maximumFractionDigits: 0 }).format(n);

/** Client-side slug matching Laravel's Str::slug for our ASCII city names. */
const citySlug = (name: string) => name.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');

function priceLabel(e: FeaturedEvent) {
    if (e.from_price != null) {
return `From ${money(e.from_price)}`;
}

    if (e.has_free) {
return 'Free';
}

    return null;
}

interface Organizer { name: string; events_count: number; next_slug: string | null }

interface LandingSections {
    organizer: { enabled: boolean; heading: string; body: string; cta_label: string; cta_url: string; image: string };
    event_time: { enabled: boolean; heading: string; items: { label: string; value: string }[] };
    nearby_cities: { enabled: boolean; heading: string; cities: Array<{ name: string; slug: string | null; lat: number | null; lng: number | null }> };
    featured_organizers: { enabled: boolean; heading: string; subheading: string };
}

const initials = (name: string) => name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]?.toUpperCase()).join('') || '?';
const AVATAR_TINTS = ['#6c63ff', '#2ec4b6', '#f5a524', '#3b82f6', '#ff6584', '#a855f7'];

export default function Welcome() {
    const { auth, featured = [], categories = [], sections, organizers = [] } = usePage().props as unknown as {
        auth?: { user?: unknown };
        featured?: FeaturedEvent[];
        categories?: { name: string; slug: string }[];
        sections?: LandingSections;
        organizers?: Organizer[];
    };
    const [q, setQ] = useState('');
    const signedIn = !!auth?.user;
    const org = sections?.organizer;
    const eventTime = sections?.event_time;
    const nearby = sections?.nearby_cities;
    const featuredOrgs = sections?.featured_organizers;

    // Ask for the visitor's location (once) so nearby cities can show distance.
    const [geo, setGeo] = useState<{ lat: number; lng: number } | null>(null);
    useEffect(() => {
        if (!nearby?.enabled || typeof navigator === 'undefined' || !navigator.geolocation) {
            return;
        }

        navigator.geolocation.getCurrentPosition(
            (pos) => setGeo({ lat: pos.coords.latitude, lng: pos.coords.longitude }),
            () => {},
            { enableHighAccuracy: false, timeout: 8000, maximumAge: 600000 },
        );
    }, [nearby?.enabled]);

    const nearbyCities = useMemo(() => {
        const list = (nearby?.cities ?? []).filter((c) => c && c.name);

        if (!geo) {
            return list.map((c) => ({ ...c, km: null as number | null }));
        }

        const R = 6371;
        const toRad = (d: number) => (d * Math.PI) / 180;
        const withKm = list.map((c) => {
            if (c.lat == null || c.lng == null) {
                return { ...c, km: null as number | null };
            }

            const dLat = toRad(c.lat - geo.lat);
            const dLng = toRad(c.lng - geo.lng);
            const s = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(geo.lat)) * Math.cos(toRad(c.lat)) * Math.sin(dLng / 2) ** 2;

            return { ...c, km: 2 * R * Math.asin(Math.sqrt(s)) };
        });

        return withKm.sort((a, b) => (a.km ?? 1e9) - (b.km ?? 1e9));
    }, [nearby?.cities, geo]);

    return (
        <>
            <Head title="Find your people — DropRSVP" />

            <div className="flex min-h-screen flex-col bg-background text-foreground">
                <PublicHeader />

                {/* ---------------------------------------------------------- Hero */}
                <section className="relative overflow-hidden">
                    <HeroArt />
                    <div className="relative mx-auto max-w-3xl px-6 pb-20 pt-16 text-center sm:pt-24 lg:pb-28 lg:pt-28">
                        <span className="inline-flex items-center gap-1.5 rounded-full border border-border bg-card/70 px-3.5 py-1.5 text-xs font-medium text-muted-foreground shadow-sm backdrop-blur">
                            <Sparkles className="size-3.5" /> Events in Kuala Lumpur &amp; beyond
                        </span>

                        <h1 className="mt-6 font-bold leading-[1.03] tracking-tight text-balance" style={{ fontSize: 'clamp(2.5rem, 6.5vw, 4.5rem)' }}>
                            Find your people.
                            <br className="hidden sm:block" /> Fill your events.
                        </h1>

                        <p className="mx-auto mt-5 max-w-xl text-base text-muted-foreground sm:text-lg">
                            Discover what’s happening near you — or host your own. Sell tickets, manage seating and
                            check guests in, all from one place.
                        </p>

                        {/* Search — big, tappable, and never zooms on iOS (16px input) */}
                        <form
                            onSubmit={(e) => {
 e.preventDefault(); router.get('/en-my/all', q ? { q } : {}); 
}}
                            className="mx-auto mt-9 flex w-full max-w-2xl flex-col gap-3 sm:flex-row"
                        >
                            <div className="flex h-16 flex-1 items-center gap-3 rounded-2xl border border-border bg-card px-5 shadow-sm transition-colors focus-within:border-foreground/50 focus-within:ring-4 focus-within:ring-foreground/5 sm:rounded-full">
                                <Search className="size-5 shrink-0 text-muted-foreground" />
                                <input
                                    value={q}
                                    onChange={(e) => setQ(e.target.value)}
                                    className="h-full w-full bg-transparent text-base outline-none placeholder:text-muted-foreground"
                                    placeholder="Search events, e.g. “live music”"
                                    aria-label="Search events"
                                />
                            </div>
                            <Button type="submit" size="lg" className="h-16 shrink-0 text-base sm:px-10">Search</Button>
                        </form>

                        <p className="mt-5 text-xs text-muted-foreground">
                            Free to browse · Instant e-tickets · QR check-in
                        </p>
                    </div>
                </section>

                {/* ------------------------------------------ Event-time chips */}
                {eventTime?.enabled && eventTime.items.length > 0 && (
                    <section className="mx-auto w-full max-w-6xl px-6 pt-10">
                        <Reveal className="flex flex-wrap items-center gap-3">
                            <h2 className="mr-2 text-sm font-semibold uppercase tracking-wide text-muted-foreground">{eventTime.heading}</h2>
                            {eventTime.items.filter((i) => i.label && i.value).map((i) => (
                                <Link key={i.value} href={`/en-my?when=${encodeURIComponent(i.value)}`} className="rounded-full border border-border bg-card px-4 py-1.5 text-sm font-medium transition-colors hover:border-foreground/40">{i.label}</Link>
                            ))}
                        </Reveal>
                    </section>
                )}

                {/* ------------------------------------------------- Categories */}
                {categories.length > 0 && (
                    <section className="mx-auto w-full max-w-6xl px-6 py-14 sm:py-16">
                        <Reveal className="mb-7 flex items-end justify-between gap-4">
                            <div>
                                <h2 className="text-2xl font-bold tracking-tight sm:text-3xl">Browse by category</h2>
                                <p className="mt-1.5 text-sm text-muted-foreground">Pick a vibe — we’ll show you what’s on.</p>
                            </div>
                            <Link href="/en-my/all" className="hidden shrink-0 items-center gap-1 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground sm:inline-flex">
                                All events <ArrowRight className="size-4" />
                            </Link>
                        </Reveal>
                        <CategoryGrid categories={categories} />
                    </section>
                )}

                {/* ---------------------------------------------- Nearby cities */}
                {nearby?.enabled && nearbyCities.length > 0 && (
                    <section className="mx-auto w-full max-w-6xl px-6 py-4">
                        <Reveal>
                            <h2 className="mb-4 text-xl font-bold tracking-tight sm:text-2xl">{nearby.heading}</h2>
                            <div className="flex flex-wrap gap-2.5">
                                {nearbyCities.map((c) => (
                                    <Link key={c.name} href={`/en-my/${c.slug ?? citySlug(c.name)}`} className="rounded-xl border border-border bg-card px-4 py-2 transition-all hover:-translate-y-0.5 hover:border-foreground/40 hover:shadow-sm">
                                        <span className="block text-sm font-medium">{c.name}</span>
                                        {c.km != null && <span className="flex items-center gap-1 text-[11px] text-muted-foreground"><MapPin className="size-3" /> {c.km < 1 ? 'under 1 km away' : `~${Math.round(c.km)} km away`}</span>}
                                    </Link>
                                ))}
                            </div>
                        </Reveal>
                    </section>
                )}

                {/* --------------------------------------------- Featured events */}
                {featured.length > 0 && (
                    <section className="border-y border-border bg-muted/30">
                        <div className="mx-auto w-full max-w-6xl px-6 py-16 sm:py-20">
                            <Reveal className="mb-8 flex items-end justify-between gap-4">
                                <div>
                                    <h2 className="text-2xl font-bold tracking-tight sm:text-3xl">Happening soon</h2>
                                    <p className="mt-1.5 text-sm text-muted-foreground">Hand-picked events worth showing up for.</p>
                                </div>
                                <Button asChild variant="outline" className="hidden shrink-0 sm:inline-flex">
                                    <Link href="/en-my/all">See all</Link>
                                </Button>
                            </Reveal>

                            <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                                {featured.map((e, i) => (
                                    <Reveal key={e.slug} delay={i * 80}>
                                        <Link href={`/en-my/e/${e.slug}`} className="group flex h-full flex-col overflow-hidden rounded-2xl border border-border bg-card shadow-sm transition-all duration-300 hover:-translate-y-1 hover:shadow-lg">
                                            <div className="aspect-[16/10] overflow-hidden bg-muted">
                                                {e.cover_image
                                                    ? <img src={e.cover_image} alt={e.title} loading="lazy" className="size-full object-cover transition-transform duration-500 group-hover:scale-105" />
                                                    : <div className="flex size-full items-center justify-center text-muted-foreground"><CalendarDays className="size-8" /></div>}
                                            </div>
                                            <div className="flex flex-1 flex-col p-5">
                                                <div className="mb-2 flex flex-wrap items-center gap-2">
                                                    {e.category && <Badge variant="secondary">{e.category}</Badge>}
                                                    {e.when && <span className="text-xs text-muted-foreground">{e.when}</span>}
                                                </div>
                                                <h3 className="text-lg font-semibold leading-snug group-hover:underline">{e.title}</h3>
                                                <div className="mt-auto flex items-center justify-between pt-4 text-sm">
                                                    <span className="truncate text-muted-foreground">{e.venue}</span>
                                                    {priceLabel(e) && <span className="shrink-0 font-semibold">{priceLabel(e)}</span>}
                                                </div>
                                            </div>
                                        </Link>
                                    </Reveal>
                                ))}
                            </div>
                        </div>
                    </section>
                )}

                {/* --------------------------------------- Featured organizers */}
                {(featuredOrgs?.enabled ?? true) && organizers.length > 0 && (
                    <section className="mx-auto w-full max-w-6xl px-6 py-14 sm:py-16">
                        <Reveal className="mb-7">
                            <h2 className="text-2xl font-bold tracking-tight sm:text-3xl">{featuredOrgs?.heading ?? 'Featured organizers'}</h2>
                            {featuredOrgs?.subheading && <p className="mt-1.5 text-sm text-muted-foreground">{featuredOrgs.subheading}</p>}
                        </Reveal>
                        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                            {organizers.map((o, i) => {
                                const tint = AVATAR_TINTS[i % AVATAR_TINTS.length];
                                const href = o.next_slug ? `/en-my/e/${o.next_slug}` : '/en-my/all';

                                return (
                                    <Reveal key={o.name + i} delay={i * 60}>
                                        <Link href={href} className="group flex h-full flex-col items-center gap-3 rounded-2xl border border-border bg-card p-5 text-center shadow-sm transition-all duration-300 hover:-translate-y-1 hover:shadow-md">
                                            <span className="flex size-14 items-center justify-center rounded-full text-lg font-bold text-white transition-transform duration-300 group-hover:scale-105" style={{ backgroundColor: tint }}>
                                                {initials(o.name)}
                                            </span>
                                            <span className="min-w-0">
                                                <span className="block truncate text-sm font-semibold">{o.name}</span>
                                                <span className="mt-0.5 block text-xs text-muted-foreground">{o.events_count} event{o.events_count === 1 ? '' : 's'}</span>
                                            </span>
                                        </Link>
                                    </Reveal>
                                );
                            })}
                        </div>
                    </section>
                )}

                {/* ------------------------------------------- Gatherings band */}
                <section className="relative overflow-hidden">
                    <div aria-hidden className="pointer-events-none absolute -left-24 top-0 size-96 rounded-full opacity-20 blur-3xl" style={{ background: 'radial-gradient(circle,#6c63ff,transparent 70%)' }} />
                    <div aria-hidden className="pointer-events-none absolute -right-24 bottom-0 size-96 rounded-full opacity-20 blur-3xl" style={{ background: 'radial-gradient(circle,#ff6584,transparent 70%)' }} />
                    <div className="relative mx-auto grid w-full max-w-6xl items-center gap-10 px-6 py-16 sm:py-20 lg:grid-cols-2">
                        <Reveal className="order-2 lg:order-1">
                            <span className="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold" style={{ backgroundColor: '#6c63ff1f', color: '#6c63ff' }}>
                                <Sparkles className="size-3.5" /> For every kind of gathering
                            </span>
                            <h2 className="mt-4 text-2xl font-bold tracking-tight sm:text-3xl lg:text-4xl">
                                From intimate meetups to sold-out nights.
                            </h2>
                            <p className="mt-3 max-w-md text-sm text-muted-foreground sm:text-base">
                                Concerts, conferences, food festivals, workshops, community runs — if people show up, DropRSVP handles the rest.
                            </p>
                            <div className="mt-6 flex flex-wrap gap-2">
                                {[
                                    { label: 'Concerts', c: '#6c63ff' }, { label: 'Conferences', c: '#3b82f6' }, { label: 'Food fests', c: '#f5a524' },
                                    { label: 'Workshops', c: '#a855f7' }, { label: 'Community', c: '#ff6584' }, { label: 'Sports', c: '#22c55e' },
                                ].map((t) => (
                                    <span key={t.label} className="rounded-full border px-3 py-1.5 text-sm font-medium" style={{ borderColor: `${t.c}55`, color: t.c, backgroundColor: `${t.c}0f` }}>
                                        {t.label}
                                    </span>
                                ))}
                            </div>
                            <Button asChild className="mt-8"><Link href="/en-my/all">Explore what’s on <ArrowRight className="size-4" /></Link></Button>
                        </Reveal>
                        <Reveal delay={120} className="order-1 lg:order-2">
                            <div className="relative mx-auto max-w-lg">
                                <div aria-hidden className="absolute inset-0 -z-10 rounded-[2.5rem] bg-gradient-to-tr from-[#6c63ff1a] via-transparent to-[#ff65841a]" />
                                <img src="/vector/undraw_having-fun_kkeu.svg" alt="" loading="lazy" className="mx-auto block h-auto w-full max-w-[22rem] sm:max-w-md drsvp-float" />
                            </div>
                        </Reveal>
                    </div>
                </section>

                {/* ----------------------------------------------- How it works */}
                <section className="border-t border-border bg-muted/30">
                    <div className="mx-auto w-full max-w-6xl px-6 py-16 sm:py-20">
                        <Reveal className="mb-10 text-center">
                            <h2 className="text-2xl font-bold tracking-tight sm:text-3xl">Everything you need to run the night</h2>
                            <p className="mx-auto mt-2 max-w-2xl text-sm text-muted-foreground sm:text-base">From the first ticket sold to the last guest through the door.</p>
                        </Reveal>
                        <div className="grid gap-6 sm:grid-cols-3">
                            {[
                                { art: 'undraw_conference-call_jgi5', tint: '#6c63ff', title: 'Discover & connect', body: 'A community-first marketplace — browse by interest and location and find events worth your time.' },
                                { art: 'undraw_festivities_q090', tint: '#f5a524', title: 'Sell tickets', body: 'Multi-tier ticketing, discount codes, seat & table management and secure online payments.' },
                                { art: 'undraw_public-speaking_m17t', tint: '#ff6584', title: 'Check them in', body: 'QR entry passes and a fast scanner so your team gets everyone through the door.' },
                            ].map((s, i) => (
                                <Reveal key={s.title} delay={i * 90}>
                                    <div className="flex h-full flex-col overflow-hidden rounded-2xl border border-border bg-card shadow-sm transition-all duration-300 hover:-translate-y-1 hover:shadow-md">
                                        <div className="flex aspect-[16/10] items-center justify-center p-6" style={{ backgroundColor: `${s.tint}12` }}>
                                            <img src={`/vector/${s.art}.svg`} alt="" loading="lazy" className="max-h-full max-w-full object-contain" />
                                        </div>
                                        <div className="p-6">
                                            <h3 className="text-lg font-semibold">{s.title}</h3>
                                            <p className="mt-2 text-sm leading-relaxed text-muted-foreground">{s.body}</p>
                                        </div>
                                    </div>
                                </Reveal>
                            ))}
                        </div>
                    </div>
                </section>

                {/* -------------------------------------------- Organizer band */}
                {(org?.enabled ?? true) && (
                    <section className="px-6 pb-20">
                        <Reveal>
                            <div className="relative mx-auto grid max-w-6xl items-center gap-8 overflow-hidden rounded-3xl bg-foreground px-8 py-14 text-background sm:px-12 sm:py-16 lg:grid-cols-2">
                                <div aria-hidden className="pointer-events-none absolute -right-16 -top-16 size-72 rounded-full opacity-50 blur-2xl" style={{ background: 'radial-gradient(circle,#6c63ff,transparent 70%)' }} />
                                <div aria-hidden className="pointer-events-none absolute -bottom-24 -left-10 size-80 rounded-full opacity-40 blur-2xl" style={{ background: 'radial-gradient(circle,#ff6584,transparent 70%)' }} />
                                <div className="relative">
                                    <h2 className="text-2xl font-bold tracking-tight sm:text-4xl">{org?.heading ?? 'Hosting an event?'}</h2>
                                    <p className="mt-3 max-w-xl text-sm text-background/70 sm:text-base">{org?.body}</p>
                                    <div className="mt-8 flex flex-col gap-3 sm:flex-row">
                                        <Button asChild size="lg" variant="secondary" className="h-12 px-8">
                                            <Link href={signedIn ? dashboard() : (org?.cta_url || '/get-started')}>{org?.cta_label || 'Create an event'}</Link>
                                        </Button>
                                        <Button asChild size="lg" variant="ghost" className="h-12 px-8 text-background hover:bg-background/10 hover:text-background">
                                            <Link href="/en-my/all">Browse events</Link>
                                        </Button>
                                    </div>
                                </div>
                                {org?.image && (
                                    <div className="relative hidden lg:block">
                                        <img src={org.image} alt="" className="ml-auto max-h-64 w-auto rounded-2xl object-cover" />
                                    </div>
                                )}
                            </div>
                        </Reveal>
                    </section>
                )}

                <PublicFooter />
            </div>
        </>
    );
}
