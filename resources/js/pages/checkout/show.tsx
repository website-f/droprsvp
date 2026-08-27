import { Head, Link, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Lock } from 'lucide-react';

interface OrderView {
    reference: string; currency: string; total: number;
    event: { title: string; slug: string; when: string | null; venue_name: string | null; is_online: boolean };
    items: Array<{ name: string; quantity: number; unit_price: number; line_total: number }>;
}

const field = 'h-11 w-full rounded-lg border border-input bg-card px-3 text-sm outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

export default function CheckoutShow({ order }: { order: OrderView }) {
    const form = useForm({ buyer_name: '', buyer_email: '', buyer_phone: '' });
    const isFree = order.total <= 0;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(`/checkout/${order.reference}/pay`);
    };

    return (
        <>
            <Head title={`Checkout · ${order.event.title}`} />
            <div className="min-h-screen bg-background text-foreground">
                <header className="border-b border-border">
                    <div className="mx-auto max-w-3xl px-6 py-4">
                        <Link href={`/e/${order.event.slug}`} className="text-xl font-bold tracking-tight">Drop<span className="text-muted-foreground">RSVP</span></Link>
                    </div>
                </header>

                <main className="mx-auto grid max-w-3xl gap-8 px-6 py-10 md:grid-cols-[1fr_300px]">
                    {/* Buyer details */}
                    <form onSubmit={submit}>
                        <h1 className="text-2xl font-bold tracking-tight">Checkout</h1>
                        <p className="mt-1 text-sm text-muted-foreground">Enter your details to {isFree ? 'register' : 'pay'}.</p>

                        <div className="mt-6 grid gap-4">
                            <div className="grid gap-1.5">
                                <Label htmlFor="buyer_name">Full name</Label>
                                <input id="buyer_name" className={field} value={form.data.buyer_name} onChange={(e) => form.setData('buyer_name', e.target.value)} />
                                {form.errors.buyer_name && <p className="text-xs text-destructive">{form.errors.buyer_name}</p>}
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="buyer_email">Email</Label>
                                <input id="buyer_email" type="email" className={field} value={form.data.buyer_email} onChange={(e) => form.setData('buyer_email', e.target.value)} />
                                {form.errors.buyer_email && <p className="text-xs text-destructive">{form.errors.buyer_email}</p>}
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="buyer_phone">Phone (optional)</Label>
                                <input id="buyer_phone" className={field} value={form.data.buyer_phone} onChange={(e) => form.setData('buyer_phone', e.target.value)} />
                            </div>
                        </div>

                        <Button type="submit" size="lg" className="mt-6 w-full" disabled={form.processing}>
                            <Lock className="size-4" /> {isFree ? 'Complete registration' : `Pay RM ${order.total.toFixed(2)}`}
                        </Button>
                    </form>

                    {/* Summary */}
                    <aside className="md:order-last">
                        <div className="rounded-2xl border border-border bg-card p-5 shadow-sm">
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
                            <div className="flex justify-between font-semibold">
                                <span>Total</span><span>RM {order.total.toFixed(2)}</span>
                            </div>
                        </div>
                        <p className="mt-3 text-center text-xs text-muted-foreground">Ref {order.reference}</p>
                    </aside>
                </main>
            </div>
        </>
    );
}
