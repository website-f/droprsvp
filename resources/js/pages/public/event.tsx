import { Head, Link, router, useForm } from '@inertiajs/react';
import { CalendarDays, Clock, Crown, Images, Info, Lock, MapPin, MessageCircle, Minus, Plus, Send, Star, Ticket, UserCheck, UserPlus, Users, Video } from 'lucide-react';
import { useState } from 'react';
import { PublicFooter, PublicHeader } from '@/components/public-header';
import { SeatMap   } from '@/components/seat-map';
import type {SeatMapSeat, SeatMapSection} from '@/components/seat-map';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

interface TicketTypeView {
    id: number; name: string; description: string | null; kind: 'paid' | 'free' | 'donation';
    price: number; compare_at_price: number | null; currency: string; on_sale: boolean; sold_out: boolean;
    min_per_order: number; max_per_order: number; remaining: number | null;
}
interface Reply { id: number; author: string; body: string; when: string; is_organizer: boolean }
interface Comment extends Reply { replies: Reply[] }
interface EventView {
    slug: string; title: string; subtitle: string | null; description: string | null;
    cover_image: string | null; gallery: string[]; category: string | null; is_online: boolean;
    venue_name: string | null; venue_address: string | null; online_url: string | null;
    when: string | null; organizer: string; organizer_id: number; organizer_slug: string; organizer_followers: number; status: string;
    show_participants: boolean; show_reviews: boolean;
    seating_enabled: boolean; seating: SeatMapSection[];
    sessions: Array<{ id: number; title: string | null; label: string | null }>;
    ticket_types: TicketTypeView[];
}
interface Participants { count: number; unlocked: boolean; list: { name: string }[]; page: number; pages: number }
interface Review { id: number; author: string; rating: number; body: string | null; when: string; mine: boolean }
interface Reviews { average: number; count: number; distribution: Record<string, number>; list: Review[]; page: number; pages: number; mine: { rating: number; body: string | null } | null }
interface Discussion { count: number; page: number; pages: number; list: Comment[] }
interface Viewer { authed: boolean; premium: boolean; is_owner: boolean; can_post: boolean; can_see_all_members: boolean; can_review: boolean; has_reviewed: boolean; is_following: boolean }
interface Seo { title: string }

type Tab = 'about' | 'participants' | 'gallery' | 'discussion' | 'reviews';

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

/** Read-only star row. */
function Stars({ value, className = 'size-4' }: { value: number; className?: string }) {
    return (
        <span className="inline-flex">
            {[1, 2, 3, 4, 5].map((i) => (
                <Star key={i} className={`${className} ${i <= Math.round(value) ? 'fill-[#f5a524] text-[#f5a524]' : 'text-muted-foreground/40'}`} />
            ))}
        </span>
    );
}

/** Interactive star picker. */
function StarInput({ value, onChange }: { value: number; onChange: (v: number) => void }) {
    const [hover, setHover] = useState(0);

    return (
        <span className="inline-flex gap-0.5" onMouseLeave={() => setHover(0)}>
            {[1, 2, 3, 4, 5].map((i) => (
                <button key={i} type="button" aria-label={`${i} star${i > 1 ? 's' : ''}`} onMouseEnter={() => setHover(i)} onClick={() => onChange(i)} className="p-0.5">
                    <Star className={`size-7 transition-colors ${i <= (hover || value) ? 'fill-[#f5a524] text-[#f5a524]' : 'text-muted-foreground/40'}`} />
                </button>
            ))}
        </span>
    );
}

export default function PublicEvent({ event, seo, participants, discussion, reviews, viewer }: { event: EventView; seo: Seo; participants: Participants; discussion: Discussion; reviews: Reviews; viewer: Viewer }) {
    const [qty, setQty] = useState<Record<number, number>>({});
    const [submitting, setSubmitting] = useState(false);
    const [tab, setTab] = useState<Tab>('about');
    const [replyTo, setReplyTo] = useState<number | null>(null);

    const ask = useForm({ body: '', parent_id: null as number | null });
    const review = useForm({ rating: reviews.mine?.rating ?? 0, body: reviews.mine?.body ?? '' });

    const capOf = (t: TicketTypeView) => Math.max(1, Math.min(t.max_per_order, t.remaining ?? t.max_per_order));
    const inc = (t: TicketTypeView) => setQty((q) => ({ ...q, [t.id]: Math.min(capOf(t), (q[t.id] || 0) === 0 ? t.min_per_order : (q[t.id] || 0) + 1) }));
    const dec = (t: TicketTypeView) => setQty((q) => ({ ...q, [t.id]: (q[t.id] || 0) <= t.min_per_order ? 0 : (q[t.id] || 0) - 1 }));

    // Reserved seating selection.
    const [selectedSeats, setSelectedSeats] = useState<Set<number>>(new Set());
    const [seatPrice, setSeatPrice] = useState<Record<number, number>>({}); // seatId -> price
    const [gaQty, setGaQty] = useState<Record<number, number>>({});
    const toggleSeat = (seat: SeatMapSeat, section: SeatMapSection) => {
        setSelectedSeats((prev) => {
            const next = new Set(prev);

            if (next.has(seat.id)) {
                next.delete(seat.id);
            } else {
                next.add(seat.id);
            }

            return next;
        });
        setSeatPrice((p) => ({ ...p, [seat.id]: section.price }));
    };
    const changeGa = (sectionId: number, q: number) => setGaQty((prev) => ({ ...prev, [sectionId]: Math.max(0, q) }));

    const seatingTotal = event.seating_enabled
        ? [...selectedSeats].reduce((sum, id) => sum + (seatPrice[id] || 0), 0)
          + event.seating.filter((s) => s.kind === 'ga').reduce((sum, s) => sum + (gaQty[s.id] || 0) * s.price, 0)
        : 0;
    const seatingCount = event.seating_enabled
        ? selectedSeats.size + Object.values(gaQty).reduce((a, b) => a + b, 0)
        : 0;

    const total = event.seating_enabled ? seatingTotal : event.ticket_types.reduce((sum, t) => sum + (qty[t.id] || 0) * t.price, 0);
    const count = event.seating_enabled ? seatingCount : Object.values(qty).reduce((a, b) => a + b, 0);

    const getTickets = () => {
        setSubmitting(true);

        if (event.seating_enabled) {
            const items = event.seating
                .filter((s) => s.kind === 'ga' && s.ticket_type_id && (gaQty[s.id] || 0) > 0)
                .map((s) => ({ ticket_type_id: s.ticket_type_id as number, quantity: gaQty[s.id] }));

            if (selectedSeats.size === 0 && items.length === 0) {
                setSubmitting(false);

                return;
            }

            router.post(`/e/${event.slug}/checkout`, { seats: [...selectedSeats], items }, { onFinish: () => setSubmitting(false) });

            return;
        }

        const items = Object.entries(qty).filter(([, q]) => q > 0).map(([id, q]) => ({ ticket_type_id: Number(id), quantity: q }));

        if (items.length === 0) {
            setSubmitting(false);

            return;
        }

        router.post(`/e/${event.slug}/checkout`, { items }, { onFinish: () => setSubmitting(false) });
    };

    const post = (parentId: number | null) => {
        ask.transform((d) => ({ ...d, parent_id: parentId }));
        ask.post(`/e/${event.slug}/comments`, { preserveScroll: true, onSuccess: () => {
            ask.reset(); setReplyTo(null);
        } });
    };

    const submitReview = (e: React.FormEvent) => {
        e.preventDefault();
        review.post(`/e/${event.slug}/reviews`, { preserveScroll: true });
    };

    // Paginate participants without leaving the page (premium/owner only).
    // reload() preserves scroll + component state by default.
    const goParticipants = (page: number) =>
        router.reload({ only: ['participants'], data: { participants_page: page } });
    const goReviews = (page: number) =>
        router.reload({ only: ['reviews'], data: { reviews_page: page } });
    const goDiscussion = (page: number) =>
        router.reload({ only: ['discussion'], data: { discussion_page: page } });

    const hiddenParticipants = Math.max(0, participants.count - participants.list.length);

    const TABS: { key: Tab; label: string; icon: typeof Info; tint: string; badge?: number }[] = [
        { key: 'about', label: 'About', icon: Info, tint: '#3b82f6' },
        ...(event.show_participants ? [{ key: 'participants' as Tab, label: 'Participants', icon: Users, tint: '#2ec4b6', badge: participants.count }] : []),
        { key: 'gallery', label: 'Gallery', icon: Images, tint: '#f5a524', badge: event.gallery.length },
        { key: 'discussion', label: 'Discussion', icon: MessageCircle, tint: '#a855f7', badge: discussion.count },
        ...(event.show_reviews ? [{ key: 'reviews' as Tab, label: 'Reviews', icon: Star, tint: '#ff6584', badge: reviews.count }] : []),
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
                    <div className="mx-auto max-w-6xl px-6 pt-6">
                        <img src={event.cover_image} alt={event.title} className="aspect-[16/6] w-full rounded-2xl object-cover" />
                    </div>
                )}

                <main className="mx-auto grid max-w-6xl gap-10 px-6 py-10 lg:grid-cols-[1fr_360px]">
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
                            <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                                <span className="text-muted-foreground">Hosted by</span>
                                <Link href={`/o/${event.organizer_slug}`} className="font-medium hover:underline">{event.organizer}</Link>
                                {event.organizer_followers > 0 && <span className="text-xs text-muted-foreground">· {event.organizer_followers} follower{event.organizer_followers === 1 ? '' : 's'}</span>}
                                {!viewer.is_owner && (
                                    viewer.authed ? (
                                        <Button type="button" variant={viewer.is_following ? 'outline' : 'default'} size="sm" className="h-7"
                                            onClick={() => router.post(`/organizers/${event.organizer_id}/follow`, {}, { preserveScroll: true })}>
                                            {viewer.is_following ? <><UserCheck className="size-3.5" /> Following</> : <><UserPlus className="size-3.5" /> Follow</>}
                                        </Button>
                                    ) : (
                                        <Button asChild variant="default" size="sm" className="h-7"><Link href="/login"><UserPlus className="size-3.5" /> Follow</Link></Button>
                                    )
                                )}
                            </div>
                        </div>

                        {/* Quick stats — jump into the matching tab */}
                        {((event.show_participants && participants.count > 0) || (event.show_reviews && reviews.count > 0)) && (
                            <div className="mt-4 flex flex-wrap items-center gap-4 text-sm">
                                {event.show_participants && participants.count > 0 && (
                                    <button type="button" onClick={() => setTab('participants')} className="flex items-center gap-1.5 text-muted-foreground transition-colors hover:text-foreground">
                                        <Users className="size-4 text-[#2ec4b6]" /> <span className="font-medium text-foreground">{participants.count}</span> going
                                    </button>
                                )}
                                {event.show_reviews && reviews.count > 0 && (
                                    <button type="button" onClick={() => setTab('reviews')} className="flex items-center gap-1.5 text-muted-foreground transition-colors hover:text-foreground">
                                        <Stars value={reviews.average} className="size-4" /> <span className="font-medium text-foreground">{reviews.average.toFixed(1)}</span> ({reviews.count})
                                    </button>
                                )}
                            </div>
                        )}

                        {/* Tabs */}
                        <div className="mt-8">
                            <div className="flex flex-wrap gap-1 border-b border-border">
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

                                {/* Participants */}
                                {tab === 'participants' && (
                                    participants.count === 0 ? (
                                        <p className="text-sm text-muted-foreground">No participants yet — be the first to grab a ticket.</p>
                                    ) : (
                                        <div className="grid gap-5">
                                            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                                {participants.list.map((m, i) => (
                                                    <div key={i} className="flex items-center gap-3 rounded-xl border border-border p-3">
                                                        <span className="flex size-9 shrink-0 items-center justify-center rounded-full text-xs font-bold text-white" style={{ backgroundColor: TINTS[i % TINTS.length] }}>{initials(m.name)}</span>
                                                        <span className="truncate text-sm font-medium">{m.name}</span>
                                                    </div>
                                                ))}
                                            </div>

                                            {/* Paywall for free/guest viewers */}
                                            {!participants.unlocked && hiddenParticipants > 0 && (
                                                <div className="flex flex-col items-center gap-2 rounded-2xl border border-[#f5a524]/30 bg-[#f5a524]/10 p-6 text-center">
                                                    <Lock className="size-5 text-[#f5a524]" />
                                                    <p className="text-sm font-medium">See all {participants.count} participants</p>
                                                    <p className="text-xs text-muted-foreground">Free members can view the first 4. Go Premium to see everyone.</p>
                                                    <Button asChild size="sm" className="mt-1"><Link href="/premium"><Crown className="size-3.5" /> Go Premium</Link></Button>
                                                </div>
                                            )}

                                            {/* Pagination for premium/owner */}
                                            {participants.unlocked && participants.pages > 1 && (
                                                <div className="flex items-center justify-between">
                                                    <Button variant="outline" size="sm" disabled={participants.page <= 1} onClick={() => goParticipants(participants.page - 1)}>← Previous</Button>
                                                    <span className="text-xs text-muted-foreground">Page {participants.page} of {participants.pages}</span>
                                                    <Button variant="outline" size="sm" disabled={participants.page >= participants.pages} onClick={() => goParticipants(participants.page + 1)}>Next →</Button>
                                                </div>
                                            )}
                                        </div>
                                    )
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
                                        {discussion.count === 0 ? (
                                            <p className="text-sm text-muted-foreground">No questions yet — be the first to ask.</p>
                                        ) : (
                                            <ul className="grid gap-4">
                                                {discussion.list.map((c) => (
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
                                        {discussion.pages > 1 && (
                                            <div className="mt-2 flex items-center justify-center gap-3">
                                                <Button variant="outline" size="sm" disabled={discussion.page <= 1} onClick={() => goDiscussion(discussion.page - 1)}>← Previous</Button>
                                                <span className="text-xs text-muted-foreground">Page {discussion.page} of {discussion.pages}</span>
                                                <Button variant="outline" size="sm" disabled={discussion.page >= discussion.pages} onClick={() => goDiscussion(discussion.page + 1)}>Next →</Button>
                                            </div>
                                        )}
                                    </div>
                                )}

                                {/* Reviews */}
                                {tab === 'reviews' && (
                                    <div className="grid gap-6">
                                        {/* Summary */}
                                        {reviews.count > 0 && (
                                            <div className="flex flex-col gap-5 rounded-2xl border border-border bg-card p-5 sm:flex-row sm:items-center">
                                                <div className="text-center">
                                                    <div className="text-4xl font-bold tabular-nums">{reviews.average.toFixed(1)}</div>
                                                    <Stars value={reviews.average} className="size-4" />
                                                    <div className="mt-1 text-xs text-muted-foreground">{reviews.count} review{reviews.count > 1 ? 's' : ''}</div>
                                                </div>
                                                <div className="flex-1 space-y-1.5">
                                                    {[5, 4, 3, 2, 1].map((s) => {
                                                        const n = reviews.distribution[String(s)] ?? 0;
                                                        const pct = reviews.count ? Math.round((n / reviews.count) * 100) : 0;

                                                        return (
                                                            <div key={s} className="flex items-center gap-2 text-xs">
                                                                <span className="w-3 tabular-nums text-muted-foreground">{s}</span>
                                                                <Star className="size-3 fill-[#f5a524] text-[#f5a524]" />
                                                                <div className="h-2 flex-1 overflow-hidden rounded-full bg-muted"><div className="h-full rounded-full bg-[#f5a524]" style={{ width: `${pct}%` }} /></div>
                                                                <span className="w-6 text-right tabular-nums text-muted-foreground">{n}</span>
                                                            </div>
                                                        );
                                                    })}
                                                </div>
                                            </div>
                                        )}

                                        {/* Composer / gate */}
                                        {!viewer.authed ? (
                                            <div className="flex items-center justify-between gap-3 rounded-xl border border-border bg-muted/40 p-4 text-sm">
                                                <span className="text-muted-foreground">Log in to review this event.</span>
                                                <Button asChild size="sm"><Link href="/login">Log in</Link></Button>
                                            </div>
                                        ) : viewer.can_review ? (
                                            <form onSubmit={submitReview} className="grid gap-3 rounded-xl border border-border p-4">
                                                <div className="text-sm font-medium">{viewer.has_reviewed ? 'Update your review' : 'Rate this event'}</div>
                                                <StarInput value={review.data.rating} onChange={(v) => review.setData('rating', v)} />
                                                {review.errors.rating && <p className="text-xs text-destructive">{review.errors.rating}</p>}
                                                <textarea value={review.data.body} onChange={(e) => review.setData('body', e.target.value)} rows={3} placeholder="Share what you thought (optional)…" className="w-full rounded-xl border border-input bg-card px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20" />
                                                <div><Button type="submit" size="sm" disabled={review.processing || review.data.rating < 1}><Star className="size-3.5" /> {viewer.has_reviewed ? 'Update review' : 'Submit review'}</Button></div>
                                            </form>
                                        ) : null}

                                        {/* List */}
                                        {reviews.list.length === 0 ? (
                                            <p className="text-sm text-muted-foreground">No reviews yet.</p>
                                        ) : (
                                            <ul className="grid gap-4">
                                                {reviews.list.map((r) => (
                                                    <li key={r.id} className="rounded-xl border border-border p-4">
                                                        <div className="flex gap-3">
                                                            <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-foreground text-xs font-bold text-background">{initials(r.author)}</span>
                                                            <div className="min-w-0 flex-1">
                                                                <div className="flex flex-wrap items-center gap-2">
                                                                    <span className="text-sm font-semibold">{r.author}</span>
                                                                    {r.mine && <Badge variant="secondary">Your review</Badge>}
                                                                    <span className="text-xs text-muted-foreground">{r.when}</span>
                                                                </div>
                                                                <Stars value={r.rating} className="mt-1 size-3.5" />
                                                                {r.body && <p className="mt-2 whitespace-pre-line text-sm text-foreground/80">{r.body}</p>}
                                                            </div>
                                                        </div>
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                        {reviews.pages > 1 && (
                                            <div className="mt-2 flex items-center justify-center gap-3">
                                                <Button variant="outline" size="sm" disabled={reviews.page <= 1} onClick={() => goReviews(reviews.page - 1)}>← Previous</Button>
                                                <span className="text-xs text-muted-foreground">Page {reviews.page} of {reviews.pages}</span>
                                                <Button variant="outline" size="sm" disabled={reviews.page >= reviews.pages} onClick={() => goReviews(reviews.page + 1)}>Next →</Button>
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Ticket selector */}
                    <aside id="tickets" className="lg:sticky lg:top-6 lg:self-start">
                        <div className="rounded-2xl border border-border bg-card p-5 shadow-sm">
                            <h2 className="mb-4 font-semibold">{event.seating_enabled ? 'Choose your seats' : 'Tickets'}</h2>

                            {event.seating_enabled ? (
                                event.seating.length === 0
                                    ? <p className="text-sm text-muted-foreground">Seating isn't available yet.</p>
                                    : <SeatMap sections={event.seating} selected={selectedSeats} onToggleSeat={toggleSeat} gaQty={gaQty} onGaChange={changeGa} />
                            ) : event.ticket_types.length === 0 ? (
                                <p className="text-sm text-muted-foreground">Tickets aren't available yet.</p>
                            ) : (
                                <div className="grid gap-3">
                                    {event.ticket_types.map((t) => (
                                        <div key={t.id} className="rounded-xl border border-border p-4">
                                            <div className="flex items-start justify-between gap-3">
                                                <div>
                                                    <div className="font-medium">{t.name}</div>
                                                    {t.description && <div className="text-xs text-muted-foreground">{t.description}</div>}
                                                    <div className="mt-1 flex items-baseline gap-2">
                                                        {t.kind === 'paid' && t.compare_at_price && t.compare_at_price > t.price && (
                                                            <span className="text-xs text-muted-foreground line-through">{t.currency} {t.compare_at_price.toFixed(2)}</span>
                                                        )}
                                                        <span className="text-sm font-semibold">{priceLabel(t)}</span>
                                                        {t.kind === 'paid' && t.compare_at_price && t.compare_at_price > t.price && (
                                                            <span className="rounded bg-[#2ec4b6]/15 px-1.5 py-0.5 text-[10px] font-semibold text-[#2ec4b6]">-{Math.round((1 - t.price / t.compare_at_price) * 100)}%</span>
                                                        )}
                                                    </div>
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
                                    <span className="text-muted-foreground">{count} {event.seating_enabled ? 'seat' : 'ticket'}{count > 1 ? 's' : ''}</span>
                                    <span className="font-semibold">RM {total.toFixed(2)}</span>
                                </div>
                            )}

                            <Button className="mt-4 w-full" size="lg" onClick={getTickets} disabled={count === 0 || submitting}>
                                <Ticket className="size-4" /> {count === 0 ? (event.seating_enabled ? 'Select seats' : 'Get tickets') : `Checkout · RM ${total.toFixed(2)}`}
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
