import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Download, Receipt } from 'lucide-react';
import { ResponsiveTable } from '@/components/responsive-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

interface TypeLine { name: string; quantity: number; unit_price: number }
interface OrderRow { reference: string; buyer: string; email: string | null; tickets: number; types: TypeLine[]; total: number; currency: string; status: string; date: string | null }
interface TypeTotal { name: string; sold: number; gross: number }
interface Paginated { data: OrderRow[]; prev_page_url: string | null; next_page_url: string | null; current_page: number; last_page: number; total: number }
interface Props { event: { slug: string; title: string; gross: number }; orders: Paginated; byType: TypeTotal[] }

const rm = (n: number) => `RM ${n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

export default function EventInvoices({ event, orders, byType }: Props) {
    return (
        <>
            <Head title={`Invoices · ${event.title}`} />
            <div className="mx-auto w-full max-w-4xl flex-1 p-4">
                <Link href="/host/invoices" className="mb-4 inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft className="size-4" /> All invoices</Link>

                <div className="mb-6 flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">{event.title}</h1>
                        <p className="text-sm text-muted-foreground">Every attendee invoice for this event.</p>
                    </div>
                    <div className="rounded-xl border border-border bg-card px-4 py-2 text-right">
                        <div className="text-xs uppercase tracking-wide text-muted-foreground">Gross revenue</div>
                        <div className="text-lg font-bold tabular-nums">{rm(event.gross)}</div>
                    </div>
                </div>

                {byType.length > 0 && (
                    <div className="mb-6 overflow-hidden rounded-2xl border border-border">
                        <div className="border-b border-border bg-muted/50 px-4 py-2.5 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                            Revenue by ticket type
                        </div>
                        <ul className="divide-y divide-border">
                            {byType.map((t) => (
                                <li key={t.name} className="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm">
                                    <span className="font-medium">{t.name}</span>
                                    <span className="flex items-center gap-4 text-muted-foreground">
                                        <span className="tabular-nums">{t.sold} sold</span>
                                        <span className="font-semibold tabular-nums text-foreground">{rm(t.gross)}</span>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {orders.data.length === 0 ? (
                    <div className="flex flex-col items-center justify-center rounded-2xl border border-dashed border-border py-16 text-center">
                        <span className="flex size-11 items-center justify-center rounded-full bg-muted text-muted-foreground"><Receipt className="size-5" /></span>
                        <p className="mt-3 text-sm font-medium">No invoices yet</p>
                        <p className="mt-1 text-xs text-muted-foreground">Attendee invoices appear here once people buy tickets.</p>
                    </div>
                ) : (
                    <>
                        <ResponsiveTable className="rounded-2xl border border-border">
                            <table className="w-full min-w-[840px] text-sm">
                                <thead className="bg-muted/50 text-left text-xs uppercase tracking-wide text-muted-foreground">
                                    <tr>
                                        <th className="px-4 py-3 font-medium">Invoice</th>
                                        <th className="px-4 py-3 font-medium">Attendee</th>
                                        <th className="px-4 py-3 font-medium">Ticket type</th>
                                        <th className="px-4 py-3 text-right font-medium">Tickets</th>
                                        <th className="px-4 py-3 text-right font-medium">Amount</th>
                                        <th className="px-4 py-3 font-medium">Status</th>
                                        <th className="px-4 py-3" />
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-border">
                                    {orders.data.map((o) => (
                                        <tr key={o.reference} className="hover:bg-muted/30">
                                            <td className="px-4 py-3">
                                                <div className="font-mono text-xs">{o.reference}</div>
                                                <div className="text-xs text-muted-foreground">{o.date ?? '—'}</div>
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="font-medium">{o.buyer}</div>
                                                {o.email && <div className="text-xs text-muted-foreground">{o.email}</div>}
                                            </td>
                                            <td className="px-4 py-3">
                                                {o.types.length === 0 ? (
                                                    <span className="text-muted-foreground">—</span>
                                                ) : (
                                                    <div className="grid gap-0.5">
                                                        {o.types.map((t, i) => (
                                                            <div key={i} className="flex items-baseline gap-1.5">
                                                                <span className="font-medium">{t.name}</span>
                                                                {t.quantity > 1 && <span className="text-xs text-muted-foreground">×{t.quantity}</span>}
                                                            </div>
                                                        ))}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-right tabular-nums">{o.tickets}</td>
                                            <td className="px-4 py-3 text-right font-medium tabular-nums">{rm(o.total)}</td>
                                            <td className="px-4 py-3"><Badge variant={o.status === 'paid' ? 'default' : 'secondary'} className="capitalize">{o.status}</Badge></td>
                                            <td className="px-4 py-3 text-right">
                                                <a href={`/my/orders/${o.reference}/receipt`} target="_blank" rel="noopener" className="inline-flex items-center gap-1 text-sm font-medium text-muted-foreground hover:text-foreground"><Download className="size-4" /> Invoice</a>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </ResponsiveTable>
                        {(orders.prev_page_url || orders.next_page_url) && (
                            <div className="mt-6 flex items-center justify-between gap-2 text-sm">
                                <span className="text-muted-foreground">Page {orders.current_page} of {orders.last_page} · {orders.total} invoices</span>
                                <div className="flex gap-2">
                                    <Button asChild variant="outline" size="sm" disabled={!orders.prev_page_url}>{orders.prev_page_url ? <Link href={orders.prev_page_url} preserveScroll>← Prev</Link> : <span>← Prev</span>}</Button>
                                    <Button asChild variant="outline" size="sm" disabled={!orders.next_page_url}>{orders.next_page_url ? <Link href={orders.next_page_url} preserveScroll>Next →</Link> : <span>Next →</span>}</Button>
                                </div>
                            </div>
                        )}
                    </>
                )}
            </div>
        </>
    );
}

EventInvoices.layout = {
    breadcrumbs: [{ title: 'Invoices', href: '/host/invoices' }, { title: 'Event', href: '#' }],
};
