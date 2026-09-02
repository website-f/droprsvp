import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowRight, CalendarDays, CheckCircle2, Headset, MapPin, MessageSquare, Send, Sparkles, Star, Tag, UserCheck, UserPlus } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { CategoryGrid } from '@/components/landing/category-grid';
import { HeroArt } from '@/components/landing/hero-art';
import {  HeroBanners } from '@/components/landing/hero-banners';
import type {Banner} from '@/components/landing/hero-banners';
import { PublicFooter, PublicHeader } from '@/components/public-header';
import { Reveal } from '@/components/reveal';
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
}

const initials = (name: string) => name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]?.toUpperCase()).join('') || '?';
const AVATAR_TINTS = ['#6c63ff', '#2ec4b6', '#f5a524', '#3b82f6', '#ff6584', '#a855f7'];

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
    const { auth, featured = [], categories = [], sections, organizers = [], cityEvents = null, forYou = null } = usePage().props as unknown as {
        auth?: { user?: unknown };
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
            <Head title="Find your people — DropRSVP" />

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
                        <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
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
                        <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
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

                            <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
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

                {/* ----------------------------------------------- Contact us */}
                {(contact?.enabled ?? true) && (
                    <section id="contact" className="border-t border-border bg-muted/30">
                        <div className="mx-auto grid w-full max-w-6xl gap-10 px-6 py-16 sm:py-20 lg:grid-cols-[1fr_1.15fr]">
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
