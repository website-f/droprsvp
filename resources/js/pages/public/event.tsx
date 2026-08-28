import { Head, Link, router, useForm } from '@inertiajs/react';
import { CalendarDays, Clock, Crown, Images, Info, Lock, MapPin, MessageCircle, Minus, Plus, Send, Ticket, Users, Video } from 'lucide-react';
import { useState } from 'react';
import { PublicFooter, PublicHeader } from '@/components/public-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

interface TicketTypeView {
    id: number; name: string; description: string | null; kind: 'paid' | 'free' | 'donation';
    price: number; currency: string; on_sale: boolean; sold_out: boolean;
    min_per_order: number; max_per_order: number; remaining: number | null;
}
interface Reply { id: number; author: string; body: string; when: string; is_organizer: boolean }
interface Comment extends Reply { replies: Reply[] }
interface EventView {
    slug: string; title: string; subtitle: string | null; description: string | null;
    cover_image: string | null; gallery: string[]; category: string | null; is_online: boolean;
    venue_name: string | null; venue_address: string | null; online_url: string | null;
    when: string | null; organizer: string; status: string;
    sessions: Array<{ id: number; title: string | null; label: string | null }>;
    ticket_types: TicketTypeView[];
}
interface Members { count: number; all_visible: boolean; list: { name: string }[] }
interface Viewer { authed: boolean; premium: boolean; is_owner: boolean; can_post: boolean; can_see_all_members: boolean }
interface Seo { title: string }

type Tab = 'about' | 'gallery' | 'discussion';

const initials = (name: string) => name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]?.toUpperCase()).join('') || '?';
const TINTS = ['#6c63ff', '#2ec4b6', '#f5a524', '#ff6584', '#3b82f6', '#a855f7'];

function priceLabel(t: TicketTypeView): string {
    if (t.kind === 'free') {
return 'Free';
}

    if (t.kind === 'donation') {
return 'Donation';
}

    return `${t.currency} ${t.price.toFixed(2)}`;
}

export default function PublicEvent({ event, seo, members, discussion, viewer }: { event: EventView; seo: Seo; members: Members; discussion: Comment[]; viewer: Viewer }) {
    const [qty, setQty] = useState<Record<number, number>>({});
    const [submitting, setSubmitting] = useState(false);
    const [tab, setTab] = useState<Tab>('about');
    const [replyTo, setReplyTo] = useState<number | null>(null);

    const ask = useForm({ body: '', parent_id: null as number | null });

    const capOf = (t: TicketTypeView) => Math.max(1, Math.min(t.max_per_order, t.remaining ?? t.max_per_order));
    const inc = (t: TicketTypeView) => setQty((q) => ({ ...q, [t.id]: Math.min(capOf(t), (q[t.id] || 0) === 0 ? t.min_per_order : (q[t.id] || 0) + 1) }));
    const dec = (t: TicketTypeView) => setQty((q) => ({ ...q, [t.id]: (q[t.id] || 0) <= t.min_per_order ? 0 : (q[t.id] || 0) - 1 }));

    const total = event.ticket_types.reduce((sum, t) => sum + (qty[t.id] || 0) * t.price, 0);
    const count = Object.values(qty).reduce((a, b) => a + b, 0);

    const getTickets = () => {
        const items = Object.entries(qty).filter(([, q]) => q > 0).map(([id, q]) => ({ ticket_type_id: Number(id), quantity: q }));

        if (items.length === 0) {
return;
}

        setSubmitting(true);
        router.post(`/e/${event.slug}/checkout`, { items }, { onFinish: () => setSubmitting(false) });
    };

    const post = (parentId: number | null) => {
        ask.transform((d) => ({ ...d, parent_id: parentId }));
        ask.post(`/e/${event.slug}/comments`, { preserveScroll: true, onSuccess: () => {
 ask.reset(); setReplyTo(null); 
} });
    };

    const hiddenMembers = Math.max(0, members.count - members.list.length);

    const TABS: { key: Tab; label: string; icon: typeof Info; tint: string; badge?: number }[] = [
        { key: 'about', label: 'About', icon: Info, tint: '#3b82f6' },
        { key: 'gallery', label: 'Gallery', icon: Images, tint: '#f5a524', badge: event.gallery.length },
        { key: 'discussion', label: 'Discussion', icon: MessageCircle, tint: '#a855f7', badge: discussion.length },
    ];

    return (
        <>
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

                        {/* Members */}
                        {members.count > 0 && (
                            <div className="mt-6 flex flex-wrap items-center gap-3 rounded-2xl border border-border bg-card p-4">
                                <div className="flex -space-x-2">
                                    {members.list.slice(0, 8).map((m, i) => (
                                        <span key={i} title={m.name} className="flex size-9 items-center justify-center rounded-full text-xs font-bold text-white ring-2 ring-card" style={{ backgroundColor: TINTS[i % TINTS.length] }}>{initials(m.name)}</span>
                                    ))}
                                </div>
                                <div className="text-sm">
                                    <span className="flex items-center gap-1.5 font-semibold"><Users className="size-4 text-muted-foreground" /> {members.count} going</span>
                                    {!members.all_visible && hiddenMembers > 0 && (
                                        <Link href="/premium" className="text-xs font-medium text-[#f5a524] hover:underline">Go Premium to see all {members.count} members →</Link>
                                    )}
                                </div>
                            </div>
                        )}

                        {/* Tabs */}
                        <div className="mt-8">
                            <div className="flex gap-1 border-b border-border">
                                {TABS.map((t) => {
                                    const active = tab === t.key;

                                    return (
                                        <button key={t.key} type="button" onClick={() => setTab(t.key)}
                                            className={`-mb-px flex items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-medium transition-colors ${active ? 'text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground'}`}
                                            style={active ? { borderColor: t.tint } : undefined}>
                                            <t.icon className="size-4" style={{ color: t.tint }} /> {t.label}
                                            {t.badge ? <span className="rounded-full bg-muted px-1.5 py-0.5 text-[11px] tabular-nums text-muted-foreground">{t.badge}</span> : null}
                                        </button>
                                    );
                                })}
                            </div>

                            <div className="pt-6">
                                {/* About */}
                                {tab === 'about' && (
                                    <div className="grid gap-8">
                                        {event.description
                                            ? <p className="whitespace-pre-line text-sm leading-relaxed text-foreground/80">{event.description}</p>
                                            : <p className="text-sm text-muted-foreground">No description yet.</p>}
                                        {event.sessions.length > 1 && (
                                            <div>
                                                <h3 className="mb-2 text-sm font-semibold">Dates</h3>
                                                <ul className="grid gap-2">
                                                    {event.sessions.map((s) => (
                                                        <li key={s.id} className="flex items-center gap-3 rounded-lg border border-border px-4 py-2 text-sm">
                                                            <Clock className="size-4 text-muted-foreground" /><span>{s.label}{s.title ? ` — ${s.title}` : ''}</span>
                                                        </li>
                                                    ))}
                                                </ul>
                                            </div>
                                        )}
                                    </div>
                                )}

                                {/* Gallery */}
                                {tab === 'gallery' && (
                                    event.gallery.length > 0 ? (
                                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                                            {event.gallery.map((src, i) => (
                                                <img key={i} src={src} alt={`${event.title} photo ${i + 1}`} className="aspect-square w-full rounded-xl border border-border object-cover" loading="lazy" />
                                            ))}
                                        </div>
                                    ) : <p className="text-sm text-muted-foreground">No photos yet.</p>
                                )}

                                {/* Discussion */}
                                {tab === 'discussion' && (
                                    <div className="grid gap-5">
                                        {/* Composer / gate */}
                                        {!viewer.authed ? (
                                            <div className="flex items-center justify-between gap-3 rounded-xl border border-border bg-muted/40 p-4 text-sm">
                                                <span className="text-muted-foreground">Log in to ask the organizer a question.</span>
                                                <Button asChild size="sm"><Link href="/login">Log in</Link></Button>
                                            </div>
                                        ) : viewer.can_post ? (
                                            <form onSubmit={(e) => {
 e.preventDefault(); post(null); 
}} className="grid gap-2">
                                                <textarea value={replyTo === null ? ask.data.body : ''} onChange={(e) => {
 ask.setData('body', e.target.value); setReplyTo(null); 
}}
                                                    rows={3} placeholder="Ask a question…" className="w-full rounded-xl border border-input bg-card px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20" />
                                                {ask.errors.body && <p className="text-xs text-destructive">{ask.errors.body}</p>}
                                                <div><Button type="submit" size="sm" disabled={ask.processing || !ask.data.body.trim()}><Send className="size-3.5" /> Post</Button></div>
                                            </form>
                                        ) : (
                                            <div className="flex items-center justify-between gap-3 rounded-xl border border-[#f5a524]/30 bg-[#f5a524]/10 p-4 text-sm">
                                                <span className="flex items-center gap-2"><Lock className="size-4 text-[#f5a524]" /> Posting in the discussion is a Premium benefit.</span>
                                                <Button asChild size="sm"><Link href="/premium"><Crown className="size-3.5" /> Go Premium</Link></Button>
                                            </div>
                                        )}

                                        {/* Thread */}
                                        {discussion.length === 0 ? (
                                            <p className="text-sm text-muted-foreground">No questions yet — be the first to ask.</p>
                                        ) : (
                                            <ul className="grid gap-4">
                                                {discussion.map((c) => (
                                                    <li key={c.id} className="rounded-xl border border-border p-4">
                                                        <CommentBody c={c} />
                                                        {(c.replies.length > 0 || viewer.is_owner) && (
                                                            <div className="mt-3 space-y-3 border-l-2 border-border pl-4">
                                                                {c.replies.map((r) => <CommentBody key={r.id} c={r} />)}
                                                                {viewer.is_owner && (
                                                                    replyTo === c.id ? (
                                                                        <form onSubmit={(e) => {
 e.preventDefault(); post(c.id); 
}} className="grid gap-2">
                                                                            <textarea autoFocus value={ask.data.body} onChange={(e) => ask.setData('body', e.target.value)} rows={2} placeholder="Write a reply…" className="w-full rounded-lg border border-input bg-card px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20" />
                                                                            <div className="flex gap-2">
                                                                                <Button type="submit" size="sm" disabled={ask.processing || !ask.data.body.trim()}><Send className="size-3.5" /> Reply</Button>
                                                                                <Button type="button" size="sm" variant="ghost" onClick={() => {
 setReplyTo(null); ask.reset(); 
}}>Cancel</Button>
                                                                            </div>
                                                                        </form>
                                                                    ) : (
                                                                        <button type="button" onClick={() => {
 setReplyTo(c.id); ask.reset(); 
}} className="text-xs font-medium text-muted-foreground hover:text-foreground">Reply</button>
                                                                    )
                                                                )}
                                                            </div>
                                                        )}
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </div>
                                )}
                            </div>
                        </div>
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

function CommentBody({ c }: { c: Reply }) {
    return (
        <div className="flex gap-3">
            <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-foreground text-xs font-bold text-background">{initials(c.author)}</span>
            <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2 text-sm">
                    <span className="font-semibold">{c.author}</span>
                    {c.is_organizer && <Badge className="gap-1 bg-[#6c63ff] text-white hover:bg-[#6c63ff]"><Crown className="size-3" /> Organizer</Badge>}
                    <span className="text-xs text-muted-foreground">{c.when}</span>
                </div>
                <p className="mt-1 whitespace-pre-line text-sm text-foreground/80">{c.body}</p>
            </div>
        </div>
    );
}
