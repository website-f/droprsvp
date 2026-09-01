import { Head, Link, router } from '@inertiajs/react';
import { ArrowDownRight, ArrowUpRight, Coins, Crown, Download, Megaphone, Receipt, RotateCcw, Search, Ticket, Wallet } from 'lucide-react';
import { useState } from 'react';
import { DonutChart, RevenueBars } from '@/components/charts';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';

interface Txn { type: string; reference: string; party: string; amount: number; direction: 'in' | 'out'; status: string; date: string | null; receipt: string | null }
interface Paginated { data: Txn[]; prev_page_url: string | null; next_page_url: string | null; current_page: number; last_page: number; total: number }
interface Props {
    kpis: { ticket_sales: number; boosts: number; subscriptions: number; refunds: number; payouts: number };
    trend: { date: string; revenue: number }[];
    breakdown: { label: string; value: number }[];
    transactions: Paginated;
    filters: { q: string; type: string; from: string; to: string };
    currency: string;
    exportUrl: string;
}

const TYPE_META: Record<string, { label: string; icon: typeof Ticket; tint: string }> = {
    ticket: { label: 'Ticket sale', icon: Ticket, tint: '#6c63ff' },
    boost: { label: 'Boost', icon: Megaphone, tint: '#f5a524' },
    subscription: { label: 'Subscription', icon: Crown, tint: '#2ec4b6' },
    payout: { label: 'Payout', icon: Wallet, tint: '#3b82f6' },
    refund: { label: 'Refund', icon: RotateCcw, tint: '#ef4444' },
};

function Kpi({ icon: Icon, label, value, tint }: { icon: typeof Ticket; label: string; value: string; tint: string }) {
    return (
        <div className="rounded-2xl border border-border bg-card p-4 shadow-sm">
            <span className="flex size-9 items-center justify-center rounded-xl" style={{ backgroundColor: `${tint}1f`, color: tint }}><Icon className="size-4" /></span>
            <div className="mt-3 text-2xl font-bold tracking-tight">{value}</div>
            <div className="text-xs text-muted-foreground">{label}</div>
        </div>
    );
}

const input = 'h-10 rounded-lg border border-input bg-card px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

export default function Finance({ kpis, trend, breakdown, transactions, filters, currency, exportUrl }: Props) {
    const rm = (n: number) => `${currency} ${n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    const [q, setQ] = useState(filters.q);

    const apply = (patch: Partial<Props['filters']>) => {
        const next = { ...filters, q, ...patch };
        router.get('/admin/finance', Object.fromEntries(Object.entries(next).filter(([, v]) => v && v !== 'all')), { preserveState: true, preserveScroll: true });
    };

    return (
        <>
            <Head title="Finance" />
            <div className="mx-auto w-full max-w-6xl flex-1 p-4">
                <div className="mb-6 flex items-center gap-2">
                    <Coins className="size-5" />
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Finance</h1>
                        <p className="text-sm text-muted-foreground">Every transaction across the platform — sales in, payouts out.</p>
                    </div>
                </div>

                {/* KPIs */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    <Kpi icon={Ticket} label="Ticket sales" value={rm(kpis.ticket_sales)} tint="#6c63ff" />
                    <Kpi icon={Megaphone} label="Boosts" value={rm(kpis.boosts)} tint="#f5a524" />
                    <Kpi icon={Crown} label="Subscriptions" value={rm(kpis.subscriptions)} tint="#2ec4b6" />
                    <Kpi icon={RotateCcw} label="Refunds" value={rm(kpis.refunds)} tint="#ef4444" />
                    <Kpi icon={Wallet} label="Payouts paid" value={rm(kpis.payouts)} tint="#3b82f6" />
                </div>

                {/* Charts */}
                <div className="mt-4 grid gap-4 lg:grid-cols-[1.6fr_1fr]">
                    <section className="rounded-2xl border border-border bg-card p-5 shadow-sm">
                        <h2 className="mb-4 text-sm font-semibold">Revenue in — last 30 days</h2>
                        <RevenueBars data={trend} />
                    </section>
                    <section className="rounded-2xl border border-border bg-card p-5 shadow-sm">
                        <h2 className="mb-4 text-sm font-semibold">Revenue mix</h2>
                        <DonutChart data={breakdown.map((b) => ({ name: b.label, value: b.value }))} />
                    </section>
                </div>

                {/* Filters */}
                <div className="mt-6 flex flex-wrap items-end gap-3">
                    <div className="relative">
                        <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                        <input className={`${input} w-56 pl-9`} value={q} onChange={(e) => setQ(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && apply({})} placeholder="Search reference / party…" />
                    </div>
                    <div className="w-44">
                        <AppSelect value={filters.type} onChange={(v) => apply({ type: v })} options={[
                            { value: 'all', label: 'All types' },
                            { value: 'ticket', label: 'Ticket sales' },
                            { value: 'boost', label: 'Boosts' },
                            { value: 'subscription', label: 'Subscriptions' },
                            { value: 'payout', label: 'Payouts' },
                            { value: 'refund', label: 'Refunds' },
                        ]} />
                    </div>
                    <input type="date" className={input} value={filters.from} onChange={(e) => apply({ from: e.target.value })} aria-label="From date" />
                    <input type="date" className={input} value={filters.to} onChange={(e) => apply({ to: e.target.value })} aria-label="To date" />
                    <Button variant="outline" onClick={() => apply({})}>Apply</Button>
                    <Button asChild variant="outline" className="ml-auto"><a href={exportUrl}><Download className="size-4" /> Export CSV</a></Button>
                </div>

                {/* Ledger */}
                <div className="mt-3 overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[720px] text-sm">
                            <thead>
                                <tr className="border-b border-border text-left text-xs uppercase tracking-wide text-muted-foreground">
                                    <th className="px-4 py-2.5 font-semibold">Date</th>
                                    <th className="px-4 py-2.5 font-semibold">Type</th>
                                    <th className="px-4 py-2.5 font-semibold">Reference</th>
                                    <th className="px-4 py-2.5 font-semibold">Party</th>
                                    <th className="px-4 py-2.5 text-right font-semibold">Amount</th>
                                    <th className="px-4 py-2.5 text-right font-semibold">Receipt</th>
                                </tr>
                            </thead>
                            <tbody>
                                {transactions.data.length === 0 ? (
                                    <tr><td colSpan={6} className="px-4 py-12 text-center text-sm text-muted-foreground">No transactions match your filters.</td></tr>
                                ) : transactions.data.map((t, i) => {
                                    const meta = TYPE_META[t.type] ?? TYPE_META.ticket;
                                    const out = t.direction === 'out';

                                    return (
                                        <tr key={`${t.reference}-${i}`} className="border-b border-border/60 last:border-0">
                                            <td className="whitespace-nowrap px-4 py-3 text-muted-foreground">{t.date}</td>
                                            <td className="px-4 py-3">
                                                <span className="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium" style={{ backgroundColor: `${meta.tint}1f`, color: meta.tint }}>
                                                    <meta.icon className="size-3.5" /> {meta.label}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 font-medium">{t.reference}</td>
                                            <td className="max-w-[16rem] truncate px-4 py-3">{t.party}</td>
                                            <td className={`whitespace-nowrap px-4 py-3 text-right font-semibold tabular-nums ${out ? 'text-destructive' : 'text-emerald-600'}`}>
                                                <span className="inline-flex items-center gap-1">{out ? <ArrowDownRight className="size-3.5" /> : <ArrowUpRight className="size-3.5" />}{out ? '−' : '+'}{rm(t.amount)}</span>
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                {t.receipt
                                                    ? <a href={t.receipt} target="_blank" rel="noopener" className="inline-flex items-center gap-1 text-xs font-medium text-muted-foreground hover:text-foreground"><Receipt className="size-3.5" /> View</a>
                                                    : <span className="text-xs text-muted-foreground/50">—</span>}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </div>

                {(transactions.prev_page_url || transactions.next_page_url) && (
                    <div className="mt-4 flex items-center justify-between">
                        <Button asChild variant="outline" disabled={!transactions.prev_page_url}>{transactions.prev_page_url ? <Link href={transactions.prev_page_url} preserveScroll>← Previous</Link> : <span>← Previous</span>}</Button>
                        <span className="text-xs text-muted-foreground">Page {transactions.current_page} of {transactions.last_page} · {transactions.total} transactions</span>
                        <Button asChild variant="outline" disabled={!transactions.next_page_url}>{transactions.next_page_url ? <Link href={transactions.next_page_url} preserveScroll>Next →</Link> : <span>Next →</span>}</Button>
                    </div>
                )}
            </div>
        </>
    );
}

Finance.layout = { breadcrumbs: [{ title: 'Finance', href: '/admin/finance' }] };
