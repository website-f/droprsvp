import { Head, Link, router } from '@inertiajs/react';
import { ArrowDownRight, ArrowUpRight, Banknote, Coins, Crown, Download, Landmark, Megaphone, Percent, Receipt, RotateCcw, Search, Ticket, Wallet, X } from 'lucide-react';
import { useState } from 'react';
import { DonutChart, RevenueBars } from '@/components/charts';
import { PaymentBadge  } from '@/components/payment-badge';
import type {PaymentInfo} from '@/components/payment-badge';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';
import { SearchableSelect } from '@/components/ui/searchable-select';

interface Txn {
    type: string;
    reference: string;
    party: string;
    amount: number;
    direction: 'in' | 'out';
    status: string;
    date: string | null;
    receipt: string | null;
    payment: PaymentInfo | null;
}
interface Paginated { data: Txn[]; prev_page_url: string | null; next_page_url: string | null; current_page: number; last_page: number; total: number }
interface Option { value: string; label: string; hint?: string }
interface Filters { q: string; type: string; direction: string; event: string; organizer: string; method: string; from: string; to: string }

interface Props {
    kpis: {
        ticket_sales: number;
        platform_fees: number;
        boosts: number;
        subscriptions: number;
        refunds: number;
        payouts: number;
        platform_revenue: number;
        owed_to_organizers: number;
    };
    trend: { date: string; revenue: number }[];
    breakdown: { label: string; value: number; direction: 'in' | 'out' }[];
    transactions: Paginated;
    filters: Filters;
    options: {
        events: Option[];
        organizers: Option[];
        methods: Option[];
        types: Option[];
        eventsTruncated: boolean;
        organizersTruncated: boolean;
    };
    showMethod: boolean;
    currency: string;
    exportUrl: string;
}

const TYPE_META: Record<string, { label: string; icon: typeof Ticket; tint: string }> = {
    ticket: { label: 'Ticket sale', icon: Ticket, tint: '#6c63ff' },
    fee: { label: 'Platform fee', icon: Percent, tint: '#0ea5e9' },
    boost: { label: 'Boost', icon: Megaphone, tint: '#f5a524' },
    subscription: { label: 'Subscription', icon: Crown, tint: '#2ec4b6' },
    payout: { label: 'Payout', icon: Wallet, tint: '#3b82f6' },
    refund: { label: 'Refund', icon: RotateCcw, tint: '#ef4444' },
};

function Kpi({ icon: Icon, label, value, tint, hint }: { icon: typeof Ticket; label: string; value: string; tint: string; hint?: string }) {
    return (
        <div className="rounded-2xl border border-border bg-card p-4 shadow-sm">
            <span className="flex size-9 items-center justify-center rounded-xl" style={{ backgroundColor: `${tint}1f`, color: tint }}><Icon className="size-4" /></span>
            <div className="mt-3 text-2xl font-bold tracking-tight">{value}</div>
            <div className="text-xs text-muted-foreground">{label}</div>
            {hint && <div className="mt-1 text-[11px] leading-snug text-muted-foreground/70">{hint}</div>}
        </div>
    );
}

const input = 'h-10 rounded-lg border border-input bg-card px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

export default function Finance({ kpis, trend, breakdown, transactions, filters, options, showMethod, currency, exportUrl }: Props) {
    const rm = (n: number) => `${currency} ${n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    const [q, setQ] = useState(filters.q);

    const apply = (patch: Partial<Filters>) => {
        const next = { ...filters, q, ...patch };
        router.get('/admin/finance', Object.fromEntries(Object.entries(next).filter(([, v]) => v && v !== 'all')), { preserveState: true, preserveScroll: true });
    };

    const reset = () => router.get('/admin/finance', {}, { preserveScroll: true });

    // Which filters are actually narrowing the view — used both to offer a
    // reset and to say plainly what the tiles above are counting.
    const active = [
        filters.type !== 'all' && 'type',
        filters.direction !== 'all' && 'direction',
        filters.event !== 'all' && 'event',
        filters.organizer !== 'all' && 'organizer',
        filters.method !== 'all' && 'method',
        filters.from && 'from',
        filters.to && 'to',
        filters.q && 'q',
    ].filter(Boolean) as string[];

    // The tiles ignore type/direction on purpose (see FinanceController::kpis),
    // so scoping them is only true of the who/when filters.
    const scoped = ['event', 'organizer', 'from', 'to'].some((k) => active.includes(k));

    const inflow = breakdown.filter((b) => b.direction === 'in');
    const outflow = breakdown.filter((b) => b.direction === 'out');
    const totalIn = kpis.ticket_sales + kpis.boosts + kpis.subscriptions;
    const totalOut = kpis.refunds + kpis.payouts;

    const colSpan = showMethod ? 7 : 6;

    return (
        <>
            <Head title="Finance" />
            <div className="mx-auto w-full max-w-6xl flex-1 p-4">
                <div className="mb-6 flex items-center gap-2">
                    <Coins className="size-5" />
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Finance</h1>
                        <p className="text-sm text-muted-foreground">Every movement of money — what came in, what we kept, and what went back out.</p>
                    </div>
                </div>

                {/* The two numbers that answer "how are we doing". Given their own
                    row because they are not the same kind of thing as the rest:
                    platform revenue is ours, the rest is money in transit. */}
                <div className="grid gap-3 sm:grid-cols-2">
                    <Kpi icon={Coins} label="Platform revenue" value={rm(kpis.platform_revenue)} tint="#22c55e" hint="Fees + boosts + subscriptions. This is what the platform actually earns." />
                    <Kpi icon={Banknote} label="Held for organizers" value={rm(kpis.owed_to_organizers)} tint="#64748b" hint="Collected on their behalf, minus fees and refunds, not yet paid out." />
                </div>

                <div className="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    <Kpi icon={Ticket} label="Ticket sales (GMV)" value={rm(kpis.ticket_sales)} tint="#6c63ff" />
                    <Kpi icon={Percent} label="Platform fees" value={rm(kpis.platform_fees)} tint="#0ea5e9" />
                    <Kpi icon={Megaphone} label="Boosts" value={rm(kpis.boosts)} tint="#f5a524" />
                    <Kpi icon={Crown} label="Subscriptions" value={rm(kpis.subscriptions)} tint="#2ec4b6" />
                    <Kpi icon={RotateCcw} label="Refunds" value={rm(kpis.refunds)} tint="#ef4444" />
                    <Kpi icon={Wallet} label="Payouts paid" value={rm(kpis.payouts)} tint="#3b82f6" />
                </div>

                <p className="mt-2 text-xs text-muted-foreground">
                    Ticket sales are the gross we processed; platform fees are the slice of it we kept, so the two overlap rather than add up.
                    {scoped && <span className="ml-1 font-medium text-foreground">Totals reflect the event, organizer and date filters below.</span>}
                </p>

                {/* Charts */}
                <div className="mt-4 grid gap-4 lg:grid-cols-[1.6fr_1fr]">
                    <section className="rounded-2xl border border-border bg-card p-5 shadow-sm">
                        <h2 className="mb-1 text-sm font-semibold">Platform revenue — last 30 days</h2>
                        <p className="mb-4 text-xs text-muted-foreground">Fees, boosts and subscriptions. Not organizers&rsquo; ticket money.</p>
                        <RevenueBars data={trend} />
                    </section>
                    <section className="rounded-2xl border border-border bg-card p-5 shadow-sm">
                        <h2 className="mb-4 text-sm font-semibold">Revenue mix</h2>
                        <DonutChart data={inflow.map((b) => ({ name: b.label, value: b.value }))} />

                        {/* In and out, side by side — the question "how much came in
                            and how much went out" should not need arithmetic. */}
                        <dl className="mt-4 grid gap-2 border-t border-border pt-4 text-xs">
                            <div className="flex items-center justify-between">
                                <dt className="flex items-center gap-1.5 text-muted-foreground"><ArrowUpRight className="size-3.5 text-emerald-600" /> Incoming</dt>
                                <dd className="font-semibold tabular-nums text-emerald-600">{rm(totalIn)}</dd>
                            </div>
                            <div className="flex items-center justify-between">
                                <dt className="flex items-center gap-1.5 text-muted-foreground"><ArrowDownRight className="size-3.5 text-destructive" /> Outgoing</dt>
                                <dd className="font-semibold tabular-nums text-destructive">{rm(totalOut)}</dd>
                            </div>
                            {outflow.map((b) => (
                                <div key={b.label} className="flex items-center justify-between pl-5 text-muted-foreground">
                                    <dt>{b.label}</dt>
                                    <dd className="tabular-nums">{rm(b.value)}</dd>
                                </div>
                            ))}
                        </dl>
                    </section>
                </div>

                {/* Filters */}
                <div className="mt-6 grid gap-3">
                    <div className="flex flex-wrap items-end gap-3">
                        <div className="relative min-w-0 flex-1 sm:flex-none">
                            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                            <input className={`${input} w-full pl-9 sm:w-56`} value={q} onChange={(e) => setQ(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && apply({})} placeholder="Search reference / party…" />
                        </div>
                        <div className="w-full sm:w-44">
                            <AppSelect value={filters.type} onChange={(v) => apply({ type: v })} options={[{ value: 'all', label: 'All types' }, ...options.types]} />
                        </div>
                        <div className="w-full sm:w-40">
                            <AppSelect value={filters.direction} onChange={(v) => apply({ direction: v })} options={[
                                { value: 'all', label: 'In and out' },
                                { value: 'in', label: 'Incoming only' },
                                { value: 'out', label: 'Outgoing only' },
                            ]} />
                        </div>
                    </div>

                    <div className="flex flex-wrap items-end gap-3">
                        <SearchableSelect
                            className="w-full sm:w-64"
                            aria-label="Filter by event"
                            value={filters.event}
                            onChange={(v) => apply({ event: v })}
                            options={[{ value: 'all', label: 'All events' }, ...options.events]}
                            searchPlaceholder="Search events…"
                            truncated={options.eventsTruncated}
                        />
                        <SearchableSelect
                            className="w-full sm:w-56"
                            aria-label="Filter by organizer or member"
                            value={filters.organizer}
                            onChange={(v) => apply({ organizer: v })}
                            options={[{ value: 'all', label: 'All organizers & members' }, ...options.organizers]}
                            searchPlaceholder="Search by name or email…"
                            truncated={options.organizersTruncated}
                        />
                        {showMethod && (
                            <div className="w-full sm:w-52">
                                <AppSelect value={filters.method} onChange={(v) => apply({ method: v })} options={[{ value: 'all', label: 'Any payment method' }, ...options.methods]} />
                            </div>
                        )}
                    </div>

                    <div className="flex flex-wrap items-end gap-3">
                        <input type="date" className={input} value={filters.from} onChange={(e) => apply({ from: e.target.value })} aria-label="From date" />
                        <input type="date" className={input} value={filters.to} onChange={(e) => apply({ to: e.target.value })} aria-label="To date" />
                        <Button variant="outline" onClick={() => apply({})}>Apply</Button>
                        {active.length > 0 && (
                            <Button variant="ghost" onClick={reset}><X className="size-4" /> Clear {active.length} filter{active.length === 1 ? '' : 's'}</Button>
                        )}
                        <Button asChild variant="outline" className="sm:ml-auto"><a href={exportUrl} download><Download className="size-4" /> Export CSV</a></Button>
                    </div>
                </div>

                {/* Ledger */}
                <div className="mt-3 rounded-2xl border border-border bg-card shadow-sm">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[820px] text-sm">
                            <thead>
                                <tr className="border-b border-border text-left text-xs uppercase tracking-wide text-muted-foreground">
                                    <th className="px-4 py-2.5 font-semibold">Date</th>
                                    <th className="px-4 py-2.5 font-semibold">Type</th>
                                    <th className="px-4 py-2.5 font-semibold">Reference</th>
                                    <th className="px-4 py-2.5 font-semibold">Party</th>
                                    {showMethod && <th className="px-4 py-2.5 font-semibold">Paid with</th>}
                                    <th className="px-4 py-2.5 text-right font-semibold">Amount</th>
                                    <th className="px-4 py-2.5 text-right font-semibold">Receipt</th>
                                </tr>
                            </thead>
                            <tbody>
                                {transactions.data.length === 0 ? (
                                    <tr><td colSpan={colSpan} className="px-4 py-12 text-center text-sm text-muted-foreground">No transactions match your filters.</td></tr>
                                ) : transactions.data.map((t, i) => {
                                    const meta = TYPE_META[t.type] ?? TYPE_META.ticket;
                                    const out = t.direction === 'out';

                                    return (
                                        <tr key={`${t.type}-${t.reference}-${i}`} className="border-b border-border/60 last:border-0">
                                            <td className="whitespace-nowrap px-4 py-3 text-muted-foreground">{t.date}</td>
                                            <td className="px-4 py-3">
                                                <span className="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium" style={{ backgroundColor: `${meta.tint}1f`, color: meta.tint }}>
                                                    <meta.icon className="size-3.5" /> {meta.label}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 font-medium">{t.reference}</td>
                                            <td className="max-w-[16rem] truncate px-4 py-3">{t.party}</td>
                                            {showMethod && <td className="px-4 py-3"><PaymentBadge payment={t.payment} /></td>}
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

                {showMethod && (
                    <p className="mt-2 flex items-start gap-1.5 text-xs text-muted-foreground">
                        <Landmark className="mt-0.5 size-3.5 shrink-0" />
                        <span>
                            Payment method is visible to superadmins only. Transactions settled before this was recorded show no method —
                            that information only exists at CHIP for those.
                        </span>
                    </p>
                )}

                {(transactions.prev_page_url || transactions.next_page_url) && (
                    <div className="mt-4 flex items-center justify-between gap-3">
                        <Button asChild variant="outline" disabled={!transactions.prev_page_url}>{transactions.prev_page_url ? <Link href={transactions.prev_page_url} preserveScroll>← Previous</Link> : <span>← Previous</span>}</Button>
                        <span className="text-center text-xs text-muted-foreground">Page {transactions.current_page} of {transactions.last_page} · {transactions.total} transactions</span>
                        <Button asChild variant="outline" disabled={!transactions.next_page_url}>{transactions.next_page_url ? <Link href={transactions.next_page_url} preserveScroll>Next →</Link> : <span>Next →</span>}</Button>
                    </div>
                )}
            </div>
        </>
    );
}

Finance.layout = { breadcrumbs: [{ title: 'Finance', href: '/admin/finance' }] };
