import { Head, Link, router } from '@inertiajs/react';
import { CalendarDays, MapPin, Send, Ticket as TicketIcon, Video, X } from 'lucide-react';
import { useConfirm } from '@/components/confirm-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

interface TicketRow { qr_token: string; attendee_name: string | null; type: string | null; status: string }
interface EventRow { title: string; slug: string; when: string | null; venue_name: string | null; is_online: boolean; cover_image: string | null }
interface OrderRow {
    reference: string; status: string; total: number; currency: string; placed_on: string | null;
    can_cancel: boolean; event: EventRow | null; tickets: TicketRow[];
}
interface Paginated { data: OrderRow[]; prev_page_url: string | null; next_page_url: string | null }

const money = (n: number, ccy: string) => new Intl.NumberFormat('en-MY', { style: 'currency', currency: ccy }).format(n);

export default function MyTickets({ orders }: { orders: Paginated }) {
    const confirm = useConfirm();
    const resend = (ref: string) => router.post(`/my/orders/${ref}/resend`, {}, { preserveScroll: true });

    const cancel = async (o: OrderRow) => {
        if (await confirm({
            title: 'Cancel this registration?',
            description: `Your spot for “${o.event?.title ?? 'this event'}” will be released and your ticket voided. This can’t be undone.`,
            confirmText: 'Cancel registration',
            destructive: true,
        })) {
            router.post(`/my/orders/${o.reference}/cancel`, {}, { preserveScroll: true });
        }
    };

    return (
        <>
            <Head title="My tickets" />
            <div className="mx-auto w-full max-w-4xl flex-1 p-4">
                <div className="mb-6">
                    <h1 className="text-2xl font-bold tracking-tight">My tickets</h1>
                    <p className="mt-1 text-sm text-muted-foreground">Your orders and tickets. Open a ticket to show it at the door, or re-send them to your email.</p>
                </div>

                {orders.data.length === 0 ? (
                    <div className="rounded-2xl border border-dashed border-border p-12 text-center">
                        <TicketIcon className="mx-auto size-8 text-muted-foreground" />
                        <p className="mt-3 text-sm font-medium">No tickets yet</p>
                        <p className="mt-1 text-sm text-muted-foreground">When you buy or register for an event, your tickets show up here.</p>
                        <Button asChild className="mt-5"><Link href="/events">Browse events</Link></Button>
                    </div>
                ) : (
                    <div className="grid gap-5">
                        {orders.data.map((o) => {
                            const refunded = o.status === 'refunded';
                            const cancelled = o.status === 'cancelled';
                            const inactive = refunded || cancelled;

                            return (
                                <div key={o.reference} className="overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
                                    {/* Event header */}
                                    <div className="flex gap-4 border-b border-border p-4">
                                        <div className="hidden size-20 shrink-0 overflow-hidden rounded-xl bg-muted sm:block">
                                            {o.event?.cover_image
                                                ? <img src={o.event.cover_image} alt="" className="size-full object-cover" />
                                                : <div className="flex size-full items-center justify-center text-muted-foreground"><CalendarDays className="size-6" /></div>}
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-start justify-between gap-3">
                                                <div className="min-w-0">
                                                    {o.event
                                                        ? <Link href={`/en-my/e/${o.event.slug}`} className="truncate text-base font-semibold hover:underline">{o.event.title}</Link>
                                                        : <span className="text-base font-semibold text-muted-foreground">Event removed</span>}
                                                    {o.event?.when && <p className="mt-0.5 flex items-center gap-1.5 text-sm text-muted-foreground"><CalendarDays className="size-3.5" />{o.event.when}</p>}
                                                    {o.event && (o.event.is_online
                                                        ? <p className="mt-0.5 flex items-center gap-1.5 text-sm text-muted-foreground"><Video className="size-3.5" />Online</p>
                                                        : o.event.venue_name && <p className="mt-0.5 flex items-center gap-1.5 text-sm text-muted-foreground"><MapPin className="size-3.5" />{o.event.venue_name}</p>)}
                                                </div>
                                                <Badge variant={inactive ? 'destructive' : 'secondary'} className="shrink-0 capitalize">{o.status}</Badge>
                                            </div>
                                            <p className="mt-2 text-xs text-muted-foreground">
                                                Order {o.reference}{o.placed_on && ` · ${o.placed_on}`} · {money(o.total, o.currency)}
                                            </p>
                                        </div>
                                    </div>

                                    {/* Tickets */}
                                    <ul className="divide-y divide-border">
                                        {o.tickets.map((t) => {
                                            const usable = t.status === 'valid' || t.status === 'checked_in';

                                            return (
                                                <li key={t.qr_token} className="flex items-center justify-between gap-3 px-4 py-3">
                                                    <div className="min-w-0">
                                                        <p className="truncate text-sm font-medium">{t.attendee_name ?? 'Guest'}</p>
                                                        <p className="text-xs text-muted-foreground">{t.type ?? 'Ticket'}{t.status === 'checked_in' && ' · Checked in'}{(t.status === 'void' || t.status === 'refunded' || t.status === 'cancelled') && ' · Not valid'}</p>
                                                    </div>
                                                    {usable
                                                        ? <Button asChild variant="outline" size="sm"><a href={`/tickets/${t.qr_token}`} target="_blank" rel="noopener">View ticket</a></Button>
                                                        : <span className="text-xs text-muted-foreground">—</span>}
                                                </li>
                                            );
                                        })}
                                    </ul>

                                    {/* Actions */}
                                    {!inactive && (
                                        <div className="flex items-center justify-end gap-2 border-t border-border bg-muted/30 px-4 py-2.5">
                                            {o.can_cancel && (
                                                <Button variant="ghost" size="sm" className="text-destructive hover:text-destructive" onClick={() => cancel(o)}>
                                                    <X className="size-4" /> Cancel registration
                                                </Button>
                                            )}
                                            <Button variant="ghost" size="sm" onClick={() => resend(o.reference)}><Send className="size-4" /> Re-send to email</Button>
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                )}

                {(orders.prev_page_url || orders.next_page_url) && (
                    <div className="mt-8 flex justify-between">
                        <Button asChild variant="outline" disabled={!orders.prev_page_url}>
                            {orders.prev_page_url ? <Link href={orders.prev_page_url}>← Newer</Link> : <span>← Newer</span>}
                        </Button>
                        <Button asChild variant="outline" disabled={!orders.next_page_url}>
                            {orders.next_page_url ? <Link href={orders.next_page_url}>Older →</Link> : <span>Older →</span>}
                        </Button>
                    </div>
                )}
            </div>
        </>
    );
}

MyTickets.layout = {
    breadcrumbs: [{ title: 'My tickets', href: '/my/tickets' }],
};
