import { Head, Link, useForm } from '@inertiajs/react';
import { Download, FileText, Receipt, Undo2 } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';

interface Row {
    reference: string; event: string | null; total: number; refunded_amount: number; currency: string;
    status: string; date: string | null; can_refund: boolean; refund_pending: boolean;
}
interface Paginated { data: Row[]; prev_page_url: string | null; next_page_url: string | null }

const money = (n: number, ccy: string) => new Intl.NumberFormat('en-MY', { style: 'currency', currency: ccy }).format(n);

export default function MyInvoices({ orders }: { orders: Paginated }) {
    const [refundFor, setRefundFor] = useState<Row | null>(null);
    const form = useForm({ reason: '' });

    const submitRefund = (e: React.FormEvent) => {
        e.preventDefault();

        if (!refundFor) {
            return;
        }

        form.post(`/my/orders/${refundFor.reference}/refund-request`, {
            preserveScroll: true,
            onSuccess: () => {
 form.reset(); setRefundFor(null); 
},
        });
    };

    return (
        <>
            <Head title="Invoices" />
            <div className="mx-auto w-full max-w-4xl flex-1 p-4">
                <div className="mb-6">
                    <h1 className="text-2xl font-bold tracking-tight">Invoices</h1>
                    <p className="mt-1 text-sm text-muted-foreground">Every purchase you’ve made — view a receipt or request a refund.</p>
                </div>

                {orders.data.length === 0 ? (
                    <div className="rounded-2xl border border-dashed border-border p-12 text-center">
                        <Receipt className="mx-auto size-8 text-muted-foreground" />
                        <p className="mt-3 text-sm text-muted-foreground">No invoices yet. Once you buy a ticket, your receipts show up here.</p>
                        <Button asChild className="mt-4"><Link href="/en-my/all">Browse events</Link></Button>
                    </div>
                ) : (
                    <div className="overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
                        {orders.data.map((o) => (
                            <div key={o.reference} className="flex flex-wrap items-center gap-3 border-b border-border px-4 py-3 last:border-0">
                                <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-muted"><FileText className="size-5 text-muted-foreground" /></span>
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="truncate text-sm font-semibold">{o.event ?? 'Order'}</span>
                                        {o.status === 'refunded' && <Badge variant="secondary">Refunded</Badge>}
                                        {o.refund_pending && <Badge variant="outline">Refund requested</Badge>}
                                        {o.refunded_amount > 0 && o.status !== 'refunded' && <Badge variant="secondary">Partly refunded</Badge>}
                                    </div>
                                    <div className="truncate text-xs text-muted-foreground">{o.reference} · {o.date}</div>
                                </div>
                                <div className="text-sm font-semibold tabular-nums">{money(o.total, o.currency)}</div>
                                <div className="flex items-center gap-2">
                                    {o.can_refund && (
                                        <Button size="sm" variant="ghost" onClick={() => {
 form.reset(); form.clearErrors(); setRefundFor(o); 
}}>
                                            <Undo2 className="size-3.5" /> Refund
                                        </Button>
                                    )}
                                    <Button asChild size="sm" variant="outline">
                                        <a href={`/my/orders/${o.reference}/receipt`} target="_blank" rel="noopener"><Download className="size-3.5" /> Receipt</a>
                                    </Button>
                                </div>
                            </div>
                        ))}
                    </div>
                )}

                {(orders.prev_page_url || orders.next_page_url) && (
                    <div className="mt-4 flex justify-between">
                        <Button asChild variant="outline" disabled={!orders.prev_page_url}>{orders.prev_page_url ? <Link href={orders.prev_page_url}>← Previous</Link> : <span>← Previous</span>}</Button>
                        <Button asChild variant="outline" disabled={!orders.next_page_url}>{orders.next_page_url ? <Link href={orders.next_page_url}>Next →</Link> : <span>Next →</span>}</Button>
                    </div>
                )}
            </div>

            <Dialog open={!!refundFor} onOpenChange={(v) => !v && setRefundFor(null)}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Request a refund</DialogTitle>
                        <DialogDescription>The organizer of “{refundFor?.event}” will review your request. Tell them why (optional).</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submitRefund} className="grid gap-3">
                        <div className="grid gap-1.5">
                            <Label htmlFor="reason">Reason</Label>
                            <textarea id="reason" rows={3} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)}
                                className="w-full rounded-lg border border-input bg-card px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20"
                                placeholder="e.g. can no longer attend" />
                            {form.errors.reason && <p className="text-xs text-destructive">{form.errors.reason}</p>}
                        </div>
                        <DialogFooter className="mt-1 gap-2">
                            <Button type="button" variant="ghost" onClick={() => setRefundFor(null)}>Cancel</Button>
                            <Button type="submit" disabled={form.processing}>{form.processing ? 'Sending…' : 'Send request'}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

MyInvoices.layout = { breadcrumbs: [{ title: 'Invoices', href: '/my/invoices' }] };
