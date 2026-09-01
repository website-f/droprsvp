import { Head, Link } from '@inertiajs/react';
import { Download, FileText, Receipt } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

interface Row { reference: string; event: string | null; total: number; currency: string; status: string; date: string | null }
interface Paginated { data: Row[]; prev_page_url: string | null; next_page_url: string | null }

const money = (n: number, ccy: string) => new Intl.NumberFormat('en-MY', { style: 'currency', currency: ccy }).format(n);

export default function MyInvoices({ orders }: { orders: Paginated }) {
    return (
        <>
            <Head title="Invoices" />
            <div className="mx-auto w-full max-w-4xl flex-1 p-4">
                <div className="mb-6">
                    <h1 className="text-2xl font-bold tracking-tight">Invoices</h1>
                    <p className="mt-1 text-sm text-muted-foreground">Every purchase you’ve made — view or download a receipt for your records.</p>
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
                                    </div>
                                    <div className="truncate text-xs text-muted-foreground">{o.reference} · {o.date}</div>
                                </div>
                                <div className="text-sm font-semibold tabular-nums">{money(o.total, o.currency)}</div>
                                <Button asChild size="sm" variant="outline">
                                    <a href={`/my/orders/${o.reference}/receipt`} target="_blank" rel="noopener"><Download className="size-3.5" /> Receipt</a>
                                </Button>
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
        </>
    );
}

MyInvoices.layout = { breadcrumbs: [{ title: 'Invoices', href: '/my/invoices' }] };
