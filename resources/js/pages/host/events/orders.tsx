import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Receipt } from 'lucide-react';
import { useConfirm } from '@/components/confirm-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

interface OrderRow { reference: string; buyer: string | null; email: string | null; tickets: number; total: number; currency: string; status: string; notes: string | null; date: string | null }
interface Props { event: { title: string; slug: string }; orders: OrderRow[] }

export default function Orders({ event, orders }: Props) {
    const flash = usePage().props.flash as { success?: string; error?: string } | undefined;
    const confirm = useConfirm();

    const refund = async (o: OrderRow) => {
        if (await confirm({ title: `Refund ${o.reference}?`, description: `${o.currency} ${o.total.toFixed(2)} will be refunded and the tickets voided.`, confirmText: 'Refund', destructive: true })) {
            router.post(`/host/events/${event.slug}/orders/${o.reference}/refund`, {}, { preserveScroll: true });
        }
    };

    return (
        <>
            <Head title={`Orders · ${event.title}`} />
            <div className="mx-auto w-full max-w-4xl flex-1 p-4">
                <div className="mb-6 flex items-center gap-3">
                    <Button asChild variant="ghost" size="icon"><Link href="/host/events"><ArrowLeft className="size-4" /></Link></Button>
                    <div>
                        <h1 className="text-xl font-bold tracking-tight">Orders</h1>
                        <p className="text-sm text-muted-foreground">{event.title}</p>
                    </div>
                </div>

                {flash?.success && <div className="mb-4 rounded-lg border border-foreground bg-foreground p-3 text-sm text-background">{flash.success}</div>}
                {flash?.error && <div className="mb-4 rounded-lg border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive">{flash.error}</div>}

                {orders.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 rounded-xl border border-dashed border-border py-16 text-center">
                        <Receipt className="size-8 text-muted-foreground" />
                        <p className="text-sm text-muted-foreground">No orders yet.</p>
                    </div>
                ) : (
                    <div className="overflow-hidden rounded-xl border border-border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left text-xs uppercase tracking-wide text-muted-foreground">
                                <tr><th className="px-4 py-3 font-medium">Buyer</th><th className="px-4 py-3 font-medium">Tickets</th><th className="px-4 py-3 font-medium">Total</th><th className="px-4 py-3 font-medium">Status</th><th className="px-4 py-3 text-right font-medium">Actions</th></tr>
                            </thead>
                            <tbody className="divide-y divide-border">
                                {orders.map((o) => (
                                    <tr key={o.reference} className="hover:bg-muted/30">
                                        <td className="px-4 py-3">
                                            <div className="font-medium">{o.buyer ?? 'Guest'}</div>
                                            <div className="text-xs text-muted-foreground">{o.email} · {o.reference} · {o.date}</div>
                                            {o.notes && <div className="mt-1 max-w-md rounded-md bg-muted/60 px-2 py-1 text-xs text-foreground/80"><span className="font-medium">Note:</span> {o.notes}</div>}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">{o.tickets}</td>
                                        <td className="px-4 py-3 font-medium whitespace-nowrap">{o.currency} {o.total.toFixed(2)}</td>
                                        <td className="px-4 py-3"><Badge variant={o.status === 'refunded' ? 'destructive' : 'default'} className="capitalize">{o.status}</Badge></td>
                                        <td className="px-4 py-3 text-right">
                                            {o.status === 'paid'
                                                ? <Button variant="outline" size="sm" onClick={() => refund(o)}>Refund</Button>
                                                : <span className="text-xs text-muted-foreground">—</span>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </>
    );
}

Orders.layout = {
    breadcrumbs: [
        { title: 'Events', href: '/host/events' },
        { title: 'Orders', href: '#' },
    ],
};
