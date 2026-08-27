import { Head, Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { CalendarDays, Clock, MapPin, Ticket, Video } from 'lucide-react';

interface TicketTypeView {
    id: number; name: string; description: string | null; kind: 'paid' | 'free' | 'donation';
    price: number; currency: string; on_sale: boolean; sold_out: boolean;
}
interface EventView {
    slug: string; title: string; subtitle: string | null; description: string | null;
    cover_image: string | null; category: string | null; is_online: boolean;
    venue_name: string | null; venue_address: string | null; online_url: string | null;
    when: string | null; organizer: string; status: string;
    sessions: Array<{ id: number; title: string | null; label: string | null }>;
    ticket_types: TicketTypeView[];
}
interface Seo { title: string; description: string; canonical: string; og_image: string | null; robots: string }

function priceLabel(t: TicketTypeView): string {
    if (t.kind === 'free') return 'Free';
    if (t.kind === 'donation') return 'Donation';
    return `${t.currency} ${t.price.toFixed(2)}`;
}

export default function PublicEvent({ event, seo, schema }: { event: EventView; seo: Seo; schema: Record<string, unknown> }) {
    const { auth } = usePage().props;
    const fromPrice = event.ticket_types.filter((t) => t.kind === 'paid').reduce<number | null>((min, t) => (min === null ? t.price : Math.min(min, t.price)), null);

    return (
        <>
            <Head title={seo.title}>
                <meta name="description" content={seo.description} head-key="description" />
                <meta name="robots" content={seo.robots} head-key="robots" />
                <link rel="canonical" href={seo.canonical} head-key="canonical" />
                <meta property="og:title" content={seo.title} head-key="ogtitle" />
                <meta property="og:description" content={seo.description} head-key="ogdesc" />
                <meta property="og:type" content="event" head-key="ogtype" />
                <meta property="og:url" content={seo.canonical} head-key="ogurl" />
                {seo.og_image && <meta property="og:image" content={seo.og_image} head-key="ogimage" />}
                <meta name="twitter:card" content="summary_large_image" head-key="twcard" />
            </Head>

            {/* Server-rendered structured data → Google rich results. */}
            <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(schema) }} />

            <div className="min-h-screen bg-background text-foreground">
                {/* Nav */}
                <header className="border-b border-border">
                    <div className="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">
                        <Link href="/" className="text-xl font-bold tracking-tight">Drop<span className="text-muted-foreground">RSVP</span></Link>
                        <nav className="flex items-center gap-2">
                            {auth?.user ? (
                                <Button asChild variant="ghost"><Link href="/host/events">My events</Link></Button>
                            ) : (
                                <>
                                    <Button asChild variant="ghost"><Link href="/login">Log in</Link></Button>
                                    <Button asChild><Link href="/register">Sign up</Link></Button>
                                </>
                            )}
                        </nav>
                    </div>
                </header>

                {event.status !== 'published' && (
                    <div className="bg-foreground px-6 py-2 text-center text-xs font-medium text-background">
                        Draft preview — only you can see this. Publish it to make it public.
                    </div>
                )}

                {/* Cover */}
                {event.cover_image && (
                    <div className="mx-auto max-w-5xl px-6 pt-6">
                        <img src={event.cover_image} alt={event.title} className="aspect-[16/6] w-full rounded-2xl object-cover" />
                    </div>
                )}

                <main className="mx-auto grid max-w-5xl gap-10 px-6 py-10 lg:grid-cols-[1fr_340px]">
                    {/* Left: details */}
                    <div>
                        {event.category && <Badge variant="secondary" className="mb-3">{event.category}</Badge>}
                        <h1 className="text-3xl font-bold tracking-tight sm:text-4xl">{event.title}</h1>
                        {event.subtitle && <p className="mt-2 text-lg text-muted-foreground">{event.subtitle}</p>}

                        <div className="mt-6 grid gap-3 text-sm">
                            {event.when && (
                                <div className="flex items-center gap-3"><CalendarDays className="size-5 text-muted-foreground" /><span>{event.when}</span></div>
                            )}
                            {event.is_online ? (
                                <div className="flex items-center gap-3"><Video className="size-5 text-muted-foreground" /><span>Online event</span></div>
                            ) : (event.venue_name || event.venue_address) && (
                                <div className="flex items-start gap-3">
                                    <MapPin className="size-5 shrink-0 text-muted-foreground" />
                                    <span>{[event.venue_name, event.venue_address].filter(Boolean).join(' · ')}</span>
                                </div>
                            )}
                            <div className="flex items-center gap-3"><span className="text-muted-foreground">Hosted by</span><span className="font-medium">{event.organizer}</span></div>
                        </div>

                        {event.description && (
                            <div className="mt-8">
                                <h2 className="text-lg font-semibold">About this event</h2>
                                <p className="mt-2 whitespace-pre-line text-sm leading-relaxed text-foreground/80">{event.description}</p>
                            </div>
                        )}

                        {event.sessions.length > 1 && (
                            <div className="mt-8">
                                <h2 className="text-lg font-semibold">Dates</h2>
                                <ul className="mt-2 grid gap-2">
                                    {event.sessions.map((s) => (
                                        <li key={s.id} className="flex items-center gap-3 rounded-lg border border-border px-4 py-2 text-sm">
                                            <Clock className="size-4 text-muted-foreground" />
                                            <span>{s.label}{s.title ? ` — ${s.title}` : ''}</span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </div>

                    {/* Right: tickets */}
                    <aside id="tickets" className="lg:sticky lg:top-6 lg:self-start">
                        <div className="rounded-2xl border border-border bg-card p-5 shadow-sm">
                            <div className="mb-4 flex items-center justify-between">
                                <h2 className="font-semibold">Tickets</h2>
                                {fromPrice !== null && <span className="text-sm text-muted-foreground">from RM {fromPrice.toFixed(2)}</span>}
                            </div>

                            {event.ticket_types.length === 0 ? (
                                <p className="text-sm text-muted-foreground">Tickets aren't available yet.</p>
                            ) : (
                                <div className="grid gap-3">
                                    {event.ticket_types.map((t) => (
                                        <div key={t.id} className="rounded-xl border border-border p-4">
                                            <div className="flex items-start justify-between gap-3">
                                                <div>
                                                    <div className="font-medium">{t.name}</div>
                                                    {t.description && <div className="text-xs text-muted-foreground">{t.description}</div>}
                                                </div>
                                                <div className="text-right font-semibold whitespace-nowrap">{priceLabel(t)}</div>
                                            </div>
                                            <div className="mt-2">
                                                {t.sold_out ? <Badge variant="destructive">Sold out</Badge>
                                                    : t.on_sale ? <Badge variant="secondary">On sale</Badge>
                                                        : <Badge variant="outline">Not on sale</Badge>}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}

                            <Button className="mt-4 w-full" size="lg" disabled={!event.ticket_types.some((t) => t.on_sale && !t.sold_out)}>
                                <Ticket className="size-4" /> Get tickets
                            </Button>
                            <p className="mt-2 text-center text-xs text-muted-foreground">Secure checkout · powered by DropRSVP</p>
                        </div>
                    </aside>
                </main>

                <footer className="border-t border-border">
                    <div className="mx-auto max-w-5xl px-6 py-8 text-sm text-muted-foreground">
                        &copy; {new Date().getFullYear()} DropRSVP
                    </div>
                </footer>
            </div>
        </>
    );
}
