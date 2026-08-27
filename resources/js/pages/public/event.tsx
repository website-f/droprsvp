import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { PublicFooter, PublicHeader } from '@/components/public-header';
import { CalendarDays, Clock, MapPin, Minus, Plus, Ticket, Video } from 'lucide-react';

interface TicketTypeView {
    id: number; name: string; description: string | null; kind: 'paid' | 'free' | 'donation';
    price: number; currency: string; on_sale: boolean; sold_out: boolean;
    min_per_order: number; max_per_order: number; remaining: number | null;
}
interface EventView {
    slug: string; title: string; subtitle: string | null; description: string | null;
    cover_image: string | null; category: string | null; is_online: boolean;
    venue_name: string | null; venue_address: string | null; online_url: string | null;
    when: string | null; organizer: string; status: string;
    sessions: Array<{ id: number; title: string | null; label: string | null }>;
    ticket_types: TicketTypeView[];
}
interface Seo { title: string }

function priceLabel(t: TicketTypeView): string {
    if (t.kind === 'free') return 'Free';
    if (t.kind === 'donation') return 'Donation';
    return `${t.currency} ${t.price.toFixed(2)}`;
}

export default function PublicEvent({ event, seo }: { event: EventView; seo: Seo }) {
    const [qty, setQty] = useState<Record<number, number>>({});
    const [submitting, setSubmitting] = useState(false);

    const capOf = (t: TicketTypeView) => Math.max(1, Math.min(t.max_per_order, t.remaining ?? t.max_per_order));
    const inc = (t: TicketTypeView) => setQty((q) => ({ ...q, [t.id]: Math.min(capOf(t), (q[t.id] || 0) === 0 ? t.min_per_order : (q[t.id] || 0) + 1) }));
    const dec = (t: TicketTypeView) => setQty((q) => ({ ...q, [t.id]: (q[t.id] || 0) <= t.min_per_order ? 0 : (q[t.id] || 0) - 1 }));

    const total = event.ticket_types.reduce((sum, t) => sum + (qty[t.id] || 0) * t.price, 0);
    const count = Object.values(qty).reduce((a, b) => a + b, 0);

    const getTickets = () => {
        const items = Object.entries(qty)
            .filter(([, q]) => q > 0)
            .map(([id, q]) => ({ ticket_type_id: Number(id), quantity: q }));
        if (items.length === 0) return;
        setSubmitting(true);
        router.post(`/e/${event.slug}/checkout`, { items }, { onFinish: () => setSubmitting(false) });
    };

    return (
        <>
            {/* SEO (title/meta/OG/JSON-LD) is server-rendered by Laravel; keep only
                the client-side <title> so the browser tab updates on SPA nav. */}
            <Head title={seo.title} />

            <div className="min-h-screen bg-background text-foreground">
                <PublicHeader />

                {event.status !== 'published' && (
                    <div className="bg-foreground px-6 py-2 text-center text-xs font-medium text-background">
                        Draft preview — only you can see this. Publish it to make it public.
                    </div>
                )}

                {event.cover_image && (
                    <div className="mx-auto max-w-5xl px-6 pt-6">
                        <img src={event.cover_image} alt={event.title} className="aspect-[16/6] w-full rounded-2xl object-cover" />
                    </div>
                )}

                <main className="mx-auto grid max-w-5xl gap-10 px-6 py-10 lg:grid-cols-[1fr_360px]">
                    <div>
                        {event.category && <Badge variant="secondary" className="mb-3">{event.category}</Badge>}
                        <h1 className="text-3xl font-bold tracking-tight sm:text-4xl">{event.title}</h1>
                        {event.subtitle && <p className="mt-2 text-lg text-muted-foreground">{event.subtitle}</p>}

                        <div className="mt-6 grid gap-3 text-sm">
                            {event.when && <div className="flex items-center gap-3"><CalendarDays className="size-5 text-muted-foreground" /><span>{event.when}</span></div>}
                            {event.is_online ? (
                                <div className="flex items-center gap-3"><Video className="size-5 text-muted-foreground" /><span>Online event</span></div>
                            ) : (event.venue_name || event.venue_address) && (
                                <div className="flex items-start gap-3"><MapPin className="size-5 shrink-0 text-muted-foreground" /><span>{[event.venue_name, event.venue_address].filter(Boolean).join(' · ')}</span></div>
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
                                            <Clock className="size-4 text-muted-foreground" /><span>{s.label}{s.title ? ` — ${s.title}` : ''}</span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </div>

                    {/* Ticket selector */}
                    <aside id="tickets" className="lg:sticky lg:top-6 lg:self-start">
                        <div className="rounded-2xl border border-border bg-card p-5 shadow-sm">
                            <h2 className="mb-4 font-semibold">Tickets</h2>

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
                                                    <div className="mt-1 text-sm font-semibold">{priceLabel(t)}</div>
                                                </div>
                                                {t.sold_out ? <Badge variant="destructive">Sold out</Badge>
                                                    : !t.on_sale ? <Badge variant="outline">Not on sale</Badge>
                                                        : (
                                                            <div className="flex items-center gap-2">
                                                                <Button type="button" variant="outline" size="icon" className="size-8" onClick={() => dec(t)} disabled={!qty[t.id]} aria-label="Decrease"><Minus className="size-3.5" /></Button>
                                                                <span className="w-5 text-center text-sm tabular-nums">{qty[t.id] || 0}</span>
                                                                <Button type="button" variant="outline" size="icon" className="size-8" onClick={() => inc(t)} disabled={(qty[t.id] || 0) >= capOf(t)} aria-label="Increase"><Plus className="size-3.5" /></Button>
                                                            </div>
                                                        )}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}

                            {count > 0 && (
                                <div className="mt-4 flex items-center justify-between text-sm">
                                    <span className="text-muted-foreground">{count} ticket{count > 1 ? 's' : ''}</span>
                                    <span className="font-semibold">RM {total.toFixed(2)}</span>
                                </div>
                            )}

                            <Button className="mt-4 w-full" size="lg" onClick={getTickets} disabled={count === 0 || submitting}>
                                <Ticket className="size-4" /> {count === 0 ? 'Get tickets' : `Checkout · RM ${total.toFixed(2)}`}
                            </Button>
                            <p className="mt-2 text-center text-xs text-muted-foreground">Secure checkout · powered by DropRSVP</p>
                        </div>
                    </aside>
                </main>

                <PublicFooter />
            </div>
        </>
    );
}
