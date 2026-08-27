import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { CheckCircle2, Clock, Ticket } from 'lucide-react';

interface OrderView {
    reference: string; status: string; currency: string; total: number;
    buyer_name?: string; buyer_email?: string;
    event: { title: string; slug: string; when: string | null; venue_name: string | null; is_online: boolean };
    items: Array<{ name: string; quantity: number; line_total: number }>;
    tickets?: Array<{ qr_token: string; attendee_name: string | null; status: string }>;
}

export default function CheckoutConfirmation({ order }: { order: OrderView }) {
    const paid = order.status === 'paid';

    return (
        <>
            <Head title={paid ? 'You’re going!' : 'Order received'} />
            <div className="min-h-screen bg-background text-foreground">
                <header className="border-b border-border">
                    <div className="mx-auto max-w-2xl px-6 py-4">
                        <Link href="/" className="text-xl font-bold tracking-tight">Drop<span className="text-muted-foreground">RSVP</span></Link>
                    </div>
                </header>

                <main className="mx-auto max-w-2xl px-6 py-12">
                    <div className="flex flex-col items-center text-center">
                        {paid ? <CheckCircle2 className="size-12" /> : <Clock className="size-12 text-muted-foreground" />}
                        <h1 className="mt-4 text-2xl font-bold tracking-tight">
                            {paid ? 'You’re going!' : 'Payment processing'}
                        </h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            {paid
                                ? `Your tickets for ${order.event.title} are confirmed. A copy has been sent to your email.`
                                : 'We’ve received your order and are confirming your payment. This page will update once it’s done.'}
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">Order {order.reference}</p>
                    </div>

                    <div className="mt-8 rounded-2xl border border-border bg-card p-5 shadow-sm">
                        <div className="font-semibold">{order.event.title}</div>
                        {order.event.when && <div className="mt-1 text-xs text-muted-foreground">{order.event.when}</div>}
                        <div className="mt-1 text-xs text-muted-foreground">{order.event.is_online ? 'Online event' : order.event.venue_name}</div>

                        <div className="my-4 h-px bg-border" />
                        <div className="grid gap-2 text-sm">
                            {order.items.map((i, idx) => (
                                <div key={idx} className="flex justify-between">
                                    <span className="text-muted-foreground">{i.quantity} × {i.name}</span>
                                    <span>RM {i.line_total.toFixed(2)}</span>
                                </div>
                            ))}
                        </div>
                        <div className="my-4 h-px bg-border" />
                        <div className="flex justify-between font-semibold"><span>Total paid</span><span>RM {order.total.toFixed(2)}</span></div>
                    </div>

                    {paid && order.tickets && order.tickets.length > 0 && (
                        <div className="mt-6 rounded-2xl border border-border bg-card p-5 shadow-sm">
                            <div className="mb-3 flex items-center gap-2 font-semibold"><Ticket className="size-4" /> Your tickets ({order.tickets.length})</div>
                            <ul className="grid gap-2">
                                {order.tickets.map((t) => (
                                    <li key={t.qr_token} className="flex items-center justify-between rounded-lg border border-border px-4 py-2 text-sm">
                                        <span>{t.attendee_name ?? 'Guest'}</span>
                                        <Link href={`/tickets/${t.qr_token}`} className="font-medium underline underline-offset-4">View ticket</Link>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    <div className="mt-8 flex justify-center">
                        <Button asChild variant="outline"><Link href={`/e/${order.event.slug}`}>Back to event</Link></Button>
                    </div>
                </main>
            </div>
        </>
    );
}
