import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowRight, CalendarDays, CalendarPlus, CheckCircle2, Compass, Headset, MapPin, MessageSquare, Send, Sparkles, Star, Tag, Ticket, UserCheck, UserPlus } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { CategoryGrid } from '@/components/landing/category-grid';
import { HeroArt } from '@/components/landing/hero-art';
import {  HeroBanners } from '@/components/landing/hero-banners';
import type {Banner} from '@/components/landing/hero-banners';
import { PublicFooter, PublicHeader } from '@/components/public-header';
import { Reveal } from '@/components/reveal';
import { contentClass } from '@/components/rich-editor';
import { AppSelect } from '@/components/ui/app-select';
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
    participants: number;
    faces: string[];
    rating: number | null;
    rating_count: number;
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

interface Organizer { id: number; slug: string; name: string; events_count: number; followers: number; next_slug: string | null; is_following: boolean; is_self: boolean }

interface LandingSections {
    hero: { style: 'classic' | 'banners'; autoplay: boolean; interval: number; banners: Banner[] };
    organizer: { enabled: boolean; heading: string; body: string; cta_label: string; cta_url: string; image: string };
    event_time: { enabled: boolean; heading: string; items: { label: string; value: string }[] };
    nearby_cities: { enabled: boolean; heading: string; cities: Array<{ name: string; slug: string | null; lat: number | null; lng: number | null }> };
    featured_organizers: { enabled: boolean; heading: string; subheading: string };
    contact: { enabled: boolean; heading: string; subheading: string };
    showcase?: { enabled: boolean };
    seo_text?: { enabled: boolean; heading: string; body: string };
}

const initials = (name: string) => name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]?.toUpperCase()).join('') || '?';
const AVATAR_TINTS = ['#6c63ff', '#2ec4b6', '#f5a524', '#3b82f6', '#ff6584', '#a855f7'];

// Cached geolocation fix so we never re-prompt the visitor on every homepage visit.
const GEO_CACHE_KEY = 'drsvp_geo';
const GEO_ASKED_KEY = 'drsvp_geo_asked';
const GEO_MAX_AGE = 7 * 24 * 60 * 60 * 1000; // 7 days
function readGeoCache(): { lat: number; lng: number } | null {
    if (typeof window === 'undefined') {
        return null;
    }

    try {
        const raw = localStorage.getItem(GEO_CACHE_KEY);

        if (raw) {
            const c = JSON.parse(raw) as { lat: number; lng: number; ts: number };

            if (c && Date.now() - c.ts < GEO_MAX_AGE) {
                return { lat: c.lat, lng: c.lng };
            }
        }
    } catch {
        /* ignore malformed cache */
    }

    return null;
}

/** A single event card — shared by Featured, "Events in {city}" and "For you". */
function EventCard({ e }: { e: FeaturedEvent }) {
    return (
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
                {(e.participants > 0 || e.rating !== null) && (
                    <div className="mt-3 flex items-center justify-between gap-3 border-t border-border/60 pt-3 text-xs text-muted-foreground">
                        {e.participants > 0 ? (
                            <span className="flex items-center gap-2">
                                <span className="flex -space-x-2">
                                    {e.faces.map((name, fi) => (
                                        <span key={fi} title={name} className="flex size-6 items-center justify-center rounded-full text-[9px] font-bold text-white ring-2 ring-card" style={{ backgroundColor: AVATAR_TINTS[fi % AVATAR_TINTS.length] }}>{initials(name)}</span>
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
    );
}

export default function Welcome() {
    const { auth, seo, featured = [], categories = [], sections, organizers = [], cityEvents = null, forYou = null } = usePage().props as unknown as {
        auth?: { user?: unknown };
        seo?: { title?: string };
        featured?: FeaturedEvent[];
        categories?: { name: string; slug: string; icon?: string | null; blurb?: string | null; color?: string | null }[];
        sections?: LandingSections;
        organizers?: Organizer[];
        cityEvents?: { city: string; slug: string; events: FeaturedEvent[] } | null;
        forYou?: FeaturedEvent[] | null;
    };
    const signedIn = !!auth?.user;
    const hero = sections?.hero;
    const org = sections?.organizer;
    const eventTime = sections?.event_time;
    const nearby = sections?.nearby_cities;
    const featuredOrgs = sections?.featured_organizers;
    const contact = sections?.contact;
    const seoText = sections?.seo_text;
    const [seoExpanded, setSeoExpanded] = useState(false);

    // Resolve the visitor's location for the nearby-cities distances. We do this at
    // most ONCE per browser: a fresh cached fix is reused silently (loaded straight
    // into state), and we only ever show the permission prompt a single time.
    const [geo, setGeo] = useState<{ lat: number; lng: number } | null>(readGeoCache);
    useEffect(() => {
        if (!nearby?.enabled || typeof navigator === 'undefined' || !navigator.geolocation) {
            return;
        }

        // A fresh cached fix already seeded state — nothing to prompt for.
        if (readGeoCache()) {
            return;
        }

        const fetchFix = () => {
            navigator.geolocation.getCurrentPosition(
                (pos) => {
                    const g = { lat: pos.coords.latitude, lng: pos.coords.longitude };
                    setGeo(g);

                    try {
                        localStorage.setItem(GEO_CACHE_KEY, JSON.stringify({ ...g, ts: Date.now() }));
                    } catch {
                        /* storage full/blocked — non-fatal */
                    }
                },
                () => {},
                { enableHighAccuracy: false, timeout: 8000, maximumAge: GEO_MAX_AGE },
            );
        };

        // If we can read the permission state, only prompt when it's actually
        // needed and only once; if already granted, fetch silently every time.
        if (navigator.permissions?.query) {
            navigator.permissions
                .query({ name: 'geolocation' as PermissionName })
                .then((status) => {
                    if (status.state === 'granted') {
                        fetchFix(); // no prompt shown
                    } else if (status.state === 'prompt' && !localStorage.getItem(GEO_ASKED_KEY)) {
                        localStorage.setItem(GEO_ASKED_KEY, '1'); // ask a single time, ever
                        fetchFix();
                    }
                    // 'denied' → respect it, never prompt.
                })
                .catch(() => {
                    if (!localStorage.getItem(GEO_ASKED_KEY)) {
                        localStorage.setItem(GEO_ASKED_KEY, '1');
                        fetchFix();
                    }
                });
        } else if (!localStorage.getItem(GEO_ASKED_KEY)) {
            // No Permissions API — fall back to a single lifetime prompt.
            localStorage.setItem(GEO_ASKED_KEY, '1');
            fetchFix();
        }
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

    // Once we know the visitor's location, refine "Events in {city}" to their
    // nearest city (server reads ?near=). Signed-in only; runs once.
    const refinedNear = useRef(false);
    useEffect(() => {
        if (!signedIn || !geo || refinedNear.current) {
            return;
        }

        const nearest = nearbyCities.find((c) => c.slug)?.slug;

        if (nearest) {
            refinedNear.current = true;
            router.reload({ only: ['cityEvents'], data: { near: nearest } });
        }
    }, [signedIn, geo, nearbyCities]);

    return (
        <>
            {/* The admin's saved SEO title (the title callback won't double-brand it). */}
            <Head title={seo?.title ?? 'Find your people — DropRSVP'} />

            <div className="flex min-h-screen flex-col bg-background text-foreground">
                <PublicHeader />

                {/* ---------------------------------------------------------- Hero */}
                {hero?.style === 'banners' && (hero.banners?.length ?? 0) > 0 ? (
                    <HeroBanners banners={hero.banners} autoplay={hero.autoplay} interval={hero.interval} />
                ) : (
                    <section className="relative overflow-hidden">
                        <HeroArt />
                        <div className="relative mx-auto max-w-3xl px-6 pb-16 pt-16 text-center sm:pt-20 lg:pb-20 lg:pt-24">
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

                            <div className="mt-8 flex flex-wrap items-center justify-center gap-3">
                                <Button asChild size="lg"><Link href="/en-my/all">Browse events</Link></Button>
                                <Button asChild size="lg" variant="outline"><Link href="/get-started">Create an event</Link></Button>
                            </div>

                            <p className="mt-5 text-xs text-muted-foreground">
                                Free to browse · Instant e-tickets · QR check-in
                            </p>
                        </div>
                    </section>
                )}

                {/* ------------------------------------------ Event-time chips */}
                {eventTime?.enabled && eventTime.items.length > 0 && (
                    <section className="mx-auto w-full max-w-6xl px-6 pt-10">
                        <Reveal className="flex flex-wrap items-center gap-3">
                            <h2 className="mr-2 text-sm font-semibold uppercase tracking-wide text-muted-foreground">{eventTime.heading}</h2>
                            {eventTime.items.filter((i) => i.label && i.value).map((i) => (
                                <Link key={i.value} href={`/en-my/all?when=${encodeURIComponent(i.value)}`} className="rounded-full border border-border bg-card px-4 py-1.5 text-sm font-medium transition-colors hover:border-foreground/40">{i.label}</Link>
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

                {/* ------------------------------------ Events in your city (signed-in) */}
                {cityEvents && cityEvents.events.length > 0 && (
                    <section className="mx-auto w-full max-w-6xl px-6 py-10">
                        <Reveal className="mb-6 flex items-end justify-between gap-4">
                            <div>
                                <h2 className="text-2xl font-bold tracking-tight sm:text-3xl">Events in {cityEvents.city}</h2>
                                <p className="mt-1.5 text-sm text-muted-foreground">Happening near you.</p>
                            </div>
                            <Link href={`/en-my/${cityEvents.slug}`} className="hidden shrink-0 items-center gap-1 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground sm:inline-flex">See all <ArrowRight className="size-4" /></Link>
                        </Reveal>
                        <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
                            {cityEvents.events.map((e, i) => <Reveal key={e.slug} delay={i * 60}><EventCard e={e} /></Reveal>)}
                        </div>
                        <Button asChild variant="outline" className="mt-6 w-full sm:hidden"><Link href={`/en-my/${cityEvents.slug}`}>See all events in {cityEvents.city}</Link></Button>
                    </section>
                )}

                {/* ------------------------------------------- For you (signed-in) */}
                {forYou && forYou.length > 0 && (
                    <section className="mx-auto w-full max-w-6xl px-6 py-10">
                        <Reveal className="mb-6">
                            <h2 className="text-2xl font-bold tracking-tight sm:text-3xl">For you</h2>
                            <p className="mt-1.5 text-sm text-muted-foreground">Picked from the kinds of events you’ve been to.</p>
                        </Reveal>
                        <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
                            {forYou.map((e, i) => <Reveal key={e.slug} delay={i * 60}><EventCard e={e} /></Reveal>)}
                        </div>
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

                            <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                                {featured.map((e, i) => (
                                    <Reveal key={e.slug} delay={i * 80}><EventCard e={e} /></Reveal>
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
                                const href = `/o/${o.slug}`;

                                return (
                                    <Reveal key={o.id} delay={i * 60}>
                                        <div className="group flex h-full flex-col items-center gap-3 rounded-2xl border border-border bg-card p-5 text-center shadow-sm transition-all duration-300 hover:-translate-y-1 hover:shadow-md">
                                            <Link href={href} className="flex flex-col items-center gap-3">
                                                <span className="flex size-14 items-center justify-center rounded-full text-lg font-bold text-white transition-transform duration-300 group-hover:scale-105" style={{ backgroundColor: tint }}>
                                                    {initials(o.name)}
                                                </span>
                                                <span className="min-w-0">
                                                    <span className="block truncate text-sm font-semibold group-hover:underline">{o.name}</span>
                                                    <span className="mt-0.5 block text-xs text-muted-foreground">{o.events_count} event{o.events_count === 1 ? '' : 's'}{o.followers > 0 ? ` · ${o.followers} follower${o.followers === 1 ? '' : 's'}` : ''}</span>
                                                </span>
                                            </Link>
                                            {!o.is_self && (signedIn ? (
                                                <Button variant={o.is_following ? 'outline' : 'default'} size="sm" className="mt-auto h-8 w-full"
                                                    onClick={() => router.post(`/organizers/${o.id}/follow`, {}, { preserveScroll: true })}>
                                                    {o.is_following ? <><UserCheck className="size-3.5" /> Following</> : <><UserPlus className="size-3.5" /> Follow</>}
                                                </Button>
                                            ) : (
                                                <Button asChild variant="default" size="sm" className="mt-auto h-8 w-full"><Link href="/login"><UserPlus className="size-3.5" /> Follow</Link></Button>
                                            ))}
                                        </div>
                                    </Reveal>
                                );
                            })}
                        </div>
                    </section>
                )}

                {/* ---------------------------- Illustration showcase (toggle) */}
                {(sections?.showcase?.enabled ?? true) && (<>
                {/* ------------------------------------------- Gatherings band */}
                <section className="relative overflow-hidden">
                    <div aria-hidden className="pointer-events-none absolute -left-24 top-0 size-96 rounded-full opacity-20 blur-3xl" style={{ background: 'radial-gradient(circle,#6c63ff,transparent 70%)' }} />
                    <div aria-hidden className="pointer-events-none absolute -right-24 bottom-0 size-96 rounded-full opacity-20 blur-3xl" style={{ background: 'radial-gradient(circle,#ff6584,transparent 70%)' }} />
                    <div className="relative mx-auto grid w-full max-w-6xl grid-cols-1 items-center gap-10 px-6 py-16 sm:py-20 lg:grid-cols-2">
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
                                {/* Explicit aspect-ratio so height never depends on the SVG's
                                    intrinsic size (Safari reports it as 0 for these files). */}
                                <img src="/vector/undraw_having-fun_kkeu.svg" alt="" loading="lazy" style={{ aspectRatio: '733 / 639' }} className="mx-auto block h-auto w-full max-w-[22rem] object-contain sm:max-w-md drsvp-float" />
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
                        <div className="grid grid-cols-1 gap-6 sm:grid-cols-3">
                            {[
                                { art: 'undraw_conference-call_jgi5', tint: '#6c63ff', title: 'Discover & connect', body: 'A community-first marketplace — browse by interest and location and find events worth your time.' },
                                { art: 'undraw_festivities_q090', tint: '#f5a524', title: 'Sell tickets', body: 'Multi-tier ticketing, discount codes, seat & table management and secure online payments.' },
                                { art: 'undraw_public-speaking_m17t', tint: '#ff6584', title: 'Check them in', body: 'QR entry passes and a fast scanner so your team gets everyone through the door.' },
                            ].map((s, i) => (
                                <Reveal key={s.title} delay={i * 90}>
                                    <div className="flex h-full min-w-0 flex-col overflow-hidden rounded-2xl border border-border bg-card shadow-sm transition-all duration-300 hover:-translate-y-1 hover:shadow-md">
                                        <div className="aspect-[16/10] p-6" style={{ backgroundColor: `${s.tint}12` }}>
                                            {/* h-full w-full + object-contain gives the SVG explicit bounds so
                                                Safari can't blow it out to its intrinsic 960px inside a flex box. */}
                                            <img src={`/vector/${s.art}.svg`} alt="" loading="lazy" className="h-full w-full object-contain" />
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
                </>)}

                {/* ------------------------------------------ How DropRSVP works */}
                {(org?.enabled ?? true) && (() => {
                    const steps = [
                        { icon: Compass, tint: '#6c63ff', title: 'Discover events near you', body: 'Browse concerts, workshops, food fests and meetups happening around you.', cta: 'Start exploring', url: '/en-my/all' },
                        { icon: Ticket, tint: '#2ec4b6', title: 'Get your ticket in seconds', body: 'Book with a secure QR pass that’s ready at the door — no queues, no printing.', cta: 'Find events', url: '/en-my/all' },
                        { icon: CalendarPlus, tint: '#f5a524', title: 'Host your own event', body: 'Create an event, sell tickets, manage seating and check guests in — all in one place.', cta: 'Create an event', url: signedIn ? dashboard() : '/get-started' },
                    ];
                    const card = (s: (typeof steps)[number]) => (
                        <div className="rounded-3xl border border-border bg-card p-5 shadow-sm transition-all duration-300 hover:-translate-y-1 hover:shadow-lg">
                            <div className="flex gap-4">
                                <span className="flex size-11 shrink-0 items-center justify-center rounded-2xl" style={{ backgroundColor: `${s.tint}1f`, color: s.tint }}><s.icon className="size-6" /></span>
                                <div>
                                    <h3 className="font-semibold leading-snug">{s.title}</h3>
                                    <p className="mt-1.5 text-sm leading-relaxed text-muted-foreground">{s.body}</p>
                                    <Link href={s.url} className="mt-3 inline-flex items-center gap-1 text-sm font-semibold text-primary hover:underline">{s.cta} <ArrowRight className="size-3.5" /></Link>
                                </div>
                            </div>
                        </div>
                    );
                    const doodle = () => (
                        <div className="flex items-center gap-3">
                            <UserPlus className="size-9 shrink-0 text-[#f5a524]" strokeWidth={1.75} />
                            <span className="max-w-[9rem] -rotate-6 text-sm font-extrabold uppercase leading-tight tracking-wide text-[#f5a524]">Your people, your night</span>
                        </div>
                    );

                    return (
                        <section className="relative overflow-hidden px-6 pt-6 pb-24">
                            <div className="mx-auto max-w-6xl">
                                <Reveal className="mb-10 text-center sm:mb-4">
                                    <h2 className="text-2xl font-bold tracking-tight sm:text-3xl">How DropRSVP works</h2>
                                    <p className="mx-auto mt-2 max-w-xl text-sm text-muted-foreground sm:text-base">From finding your next night out to hosting your own — three simple steps.</p>
                                </Reveal>

                                {/* Desktop: flowing, staggered layout with hand-drawn connectors */}
                                <Reveal className="hidden lg:block">
                                    <div className="relative mx-auto" style={{ width: 960, height: 560 }}>
                                        <svg viewBox="0 0 960 560" fill="none" preserveAspectRatio="none" className="pointer-events-none absolute inset-0 size-full text-muted-foreground/40" aria-hidden>
                                            {/* card 1 → card 2 : a little loop, then arrow right */}
                                            <path d="M305 118 C 392 74 424 74 456 122 C 482 162 520 172 648 152" stroke="currentColor" strokeWidth="2" strokeLinecap="round" fill="none" />
                                            <path d="M634 142 L650 152 L634 162" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" fill="none" />
                                            {/* card 2 → card 3 : sweep down and back to the left */}
                                            <path d="M812 258 C 856 356 834 428 664 414" stroke="currentColor" strokeWidth="2" strokeLinecap="round" fill="none" />
                                            <path d="M681 402 L662 414 L678 428" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" fill="none" />
                                        </svg>

                                        <div className="absolute" style={{ left: 0, top: 40, width: 320 }}>{card(steps[0])}</div>
                                        <div className="absolute" style={{ left: 640, top: 60, width: 320 }}>{card(steps[1])}</div>
                                        <div className="absolute" style={{ left: 320, top: 348, width: 340 }}>{card(steps[2])}</div>
                                        <div className="absolute" style={{ left: 24, top: 430 }}>{doodle()}</div>
                                    </div>
                                </Reveal>

                                {/* Mobile / tablet: stacked with soft connectors */}
                                <div className="mt-4 space-y-3 lg:hidden">
                                    {steps.map((s, i) => (
                                        <div key={s.title}>
                                            <Reveal delay={i * 80}>{card(s)}</Reveal>
                                            {i < steps.length - 1 && (
                                                <div className="flex justify-center py-1 text-muted-foreground/40" aria-hidden><ArrowDown className="size-5" /></div>
                                            )}
                                        </div>
                                    ))}
                                    <div className="pt-3">{doodle()}</div>
                                </div>
                            </div>
                        </section>
                    );
                })()}

                {/* --------------------------------------------- SEO text block */}
                {seoText?.enabled && seoText.body && (
                    <section className="border-t border-border bg-muted/20">
                        <div className="mx-auto w-full max-w-4xl px-6 py-12">
                            {seoText.heading && <h2 className="text-xl font-bold tracking-tight">{seoText.heading}</h2>}
                            <div className={`relative mt-3 ${seoExpanded ? '' : 'max-h-28 overflow-hidden'}`}>
                                <div className={contentClass} dangerouslySetInnerHTML={{ __html: seoText.body }} />
                                {!seoExpanded && <div className="pointer-events-none absolute inset-x-0 bottom-0 h-14 bg-gradient-to-t from-muted/20 to-transparent" />}
                            </div>
                            <button type="button" onClick={() => setSeoExpanded((v) => !v)} className="mt-2 text-sm font-semibold text-primary hover:underline">
                                {seoExpanded ? 'Show less' : 'Read more'}
                            </button>
                        </div>
                    </section>
                )}

                {/* ----------------------------------------------- Contact us */}
                {(contact?.enabled ?? true) && (
                    <section id="contact" className="border-t border-border bg-muted/30">
                        <div className="mx-auto grid w-full max-w-6xl grid-cols-1 gap-10 px-6 py-16 sm:py-20 lg:grid-cols-[1fr_1.15fr]">
                            <Reveal>
                                <span className="inline-flex items-center gap-2 rounded-full border border-border bg-card px-3 py-1 text-xs font-medium text-muted-foreground"><Headset className="size-3.5" /> We’re here to help</span>
                                <h2 className="mt-4 text-2xl font-bold tracking-tight sm:text-3xl">{contact?.heading ?? 'Get in touch'}</h2>
                                <p className="mt-3 max-w-md text-sm leading-relaxed text-muted-foreground sm:text-base">{contact?.subheading}</p>
                                <ul className="mt-8 grid gap-4 text-sm">
                                    {[[Headset, 'Support', 'Help with tickets, check-in and your account'], [Tag, 'Sales', 'Pricing, demos and partnerships'], [MessageSquare, 'General enquiry', 'Anything else on your mind']].map(([Icon, t, d], i) => (
                                        <li key={i} className="flex items-start gap-3">
                                            <span className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-foreground text-background"><Icon className="size-4" /></span>
                                            <div><div className="font-medium">{t as string}</div><div className="text-muted-foreground">{d as string}</div></div>
                                        </li>
                                    ))}
                                </ul>
                            </Reveal>
                            <Reveal delay={120}>
                                <ContactForm />
                            </Reveal>
                        </div>
                    </section>
                )}

                <PublicFooter />
            </div>
        </>
    );
}

const CONTACT_CATEGORIES = [{ value: 'support', label: 'Support' }, { value: 'sales', label: 'Sales' }, { value: 'enquiry', label: 'General enquiry' }];
const cfield = 'h-11 w-full rounded-xl border border-input bg-card px-3.5 text-sm outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

/** Contact form embedded in the landing page — posts to /contact. */
function ContactForm() {
    const [sent, setSent] = useState(false);
    const form = useForm({ name: '', email: '', phone: '', category: 'enquiry', message: '' });
    const { data, setData, processing, errors } = form;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/contact', { preserveScroll: true, onSuccess: () => {
            form.reset(); setSent(true);
        } });
    };

    if (sent) {
        return (
            <div className="flex h-full flex-col items-center justify-center gap-3 rounded-2xl border border-border bg-card p-8 text-center shadow-sm">
                <span className="flex size-14 items-center justify-center rounded-2xl bg-foreground text-background"><CheckCircle2 className="size-7" /></span>
                <h3 className="text-xl font-bold tracking-tight">Message sent</h3>
                <p className="max-w-sm text-sm text-muted-foreground">Thanks for reaching out — we’ll get back to you soon.</p>
                <Button variant="outline" className="mt-2" onClick={() => setSent(false)}>Send another message</Button>
            </div>
        );
    }

    return (
        <form onSubmit={submit} className="grid gap-4 rounded-2xl border border-border bg-card p-6 shadow-sm sm:p-8">
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-1.5 sm:col-span-2">
                    <label htmlFor="c-name" className="text-sm font-medium">Name</label>
                    <input id="c-name" className={cfield} value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="Your name" />
                    {errors.name && <p className="text-xs text-destructive">{errors.name}</p>}
                </div>
                <div className="grid gap-1.5">
                    <label htmlFor="c-email" className="text-sm font-medium">Email</label>
                    <input id="c-email" type="email" className={cfield} value={data.email} onChange={(e) => setData('email', e.target.value)} placeholder="you@example.com" />
                    {errors.email && <p className="text-xs text-destructive">{errors.email}</p>}
                </div>
                <div className="grid gap-1.5">
                    <label htmlFor="c-phone" className="text-sm font-medium">Phone</label>
                    <input id="c-phone" className={cfield} value={data.phone} onChange={(e) => setData('phone', e.target.value)} placeholder="+60 12-345 6789" />
                    {errors.phone && <p className="text-xs text-destructive">{errors.phone}</p>}
                </div>
            </div>
            <div className="grid gap-1.5">
                <label className="text-sm font-medium">How can we help?</label>
                <AppSelect value={data.category} onChange={(v) => setData('category', v)} options={CONTACT_CATEGORIES} />
            </div>
            <div className="grid gap-1.5">
                <label htmlFor="c-msg" className="text-sm font-medium">Message</label>
                <textarea id="c-msg" rows={4} className="w-full rounded-xl border border-input bg-card px-3.5 py-2.5 text-sm outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20" value={data.message} onChange={(e) => setData('message', e.target.value)} placeholder="Tell us a bit more…" />
                {errors.message && <p className="text-xs text-destructive">{errors.message}</p>}
            </div>
            <Button type="submit" size="lg" disabled={processing}><Send className="size-4" /> Send message</Button>
        </form>
    );
}
