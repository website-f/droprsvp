import { Head, Link, router } from '@inertiajs/react';
import { ArrowUpDown, CalendarCheck, CalendarDays, ChartColumn, Download, Eye, Search, Ticket, Users, Wallet } from 'lucide-react';
import { useState } from 'react';
import { BarsChart, DonutChart, PALETTE, RevenueBars, TrendChart } from '@/components/charts';
import { Button } from '@/components/ui/button';

interface Slice { name: string; value: number }
interface Reach { date: string; impressions: number; clicks: number }
interface EventRow { slug: string; title: string; status: string; when: string | null; impressions: number; clicks: number; ctr: number; sold: number; revenue: number }
interface Paginated { data: EventRow[]; prev_page_url: string | null; next_page_url: string | null; current_page: number; last_page: number; total: number }
interface Props {
    kpis: { events: number; published: number; users: number; tickets: number; revenue: number; impressions: number };
    reach: Reach[];
    revenue: { date: string; revenue: number }[];
    topEvents: Slice[];
    demographics: { gender: Slice[]; age: Slice[]; source: Slice[] };
    events: Paginated;
    filters: { q: string; sort: string; dir: string };
    exportUrl: string;
}

const rm = (n: number) => `RM ${n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

function Kpi({ icon: Icon, label, value, tint }: { icon: typeof Eye; label: string; value: string; tint: string }) {
    return (
        <div className="rounded-2xl border border-border bg-card p-4 shadow-sm">
            <span className="flex size-9 items-center justify-center rounded-xl" style={{ backgroundColor: `${tint}1f`, color: tint }}><Icon className="size-4" /></span>
            <div className="mt-3 text-2xl font-bold tracking-tight">{value}</div>
            <div className="text-xs text-muted-foreground">{label}</div>
        </div>
    );
}

function Panel({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <section className="rounded-2xl border border-border bg-card p-5 shadow-sm">
            <h2 className="mb-4 text-sm font-semibold">{title}</h2>
            {children}
        </section>
    );
}

function Th({ label, k, activeSort, onSort, className = '' }: { label: string; k?: string; activeSort?: string; onSort?: (k: string) => void; className?: string }) {
    return (
        <th className={`px-3 py-2 text-left font-medium ${className}`}>
            {k && onSort ? (
                <button onClick={() => onSort(k)} className={`inline-flex items-center gap-1 hover:text-foreground ${activeSort === k ? 'text-foreground' : ''}`}>
                    {label} <ArrowUpDown className="size-3" />
                </button>
            ) : label}
        </th>
    );
}

export default function AdminAnalytics({ kpis, reach, revenue, topEvents, demographics, events, filters, exportUrl }: Props) {
    const [q, setQ] = useState(filters.q);

    const query = () => {
        const params: Record<string, string> = {};

        if (q) {
            params.q = q;
        }

        if (filters.sort !== 'created_at') {
            params.sort = filters.sort;
        }

        if (filters.dir !== 'desc') {
            params.dir = filters.dir;
        }

        return params;
    };

    const search = (e: React.FormEvent) => {
        e.preventDefault();
        router.get('/admin/analytics', query(), { preserveScroll: true, preserveState: true });
    };
    const sortBy = (key: string) => {
        const dir = filters.sort === key && filters.dir === 'desc' ? 'asc' : 'desc';
        router.get('/admin/analytics', { ...query(), sort: key, dir }, { preserveScroll: true, preserveState: true });
    };

    return (
        <>
            <Head title="Analytics" />
            <div className="mx-auto w-full max-w-6xl flex-1 p-4">
                <div className="mb-6">
                    <h1 className="mb-1 text-2xl font-bold tracking-tight">Platform analytics</h1>
                    <p className="text-sm text-muted-foreground">Everything happening across DropRSVP.</p>
                </div>

                <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                    <Kpi icon={CalendarDays} label="Events" value={kpis.events.toLocaleString()} tint={PALETTE[0]} />
                    <Kpi icon={CalendarCheck} label="Published" value={kpis.published.toLocaleString()} tint={PALETTE[2]} />
                    <Kpi icon={Users} label="Users" value={kpis.users.toLocaleString()} tint={PALETTE[4]} />
                    <Kpi icon={Ticket} label="Tickets sold" value={kpis.tickets.toLocaleString()} tint={PALETTE[3]} />
                    <Kpi icon={Wallet} label="Revenue" value={rm(kpis.revenue)} tint={PALETTE[6]} />
                    <Kpi icon={Eye} label="Impressions" value={kpis.impressions.toLocaleString()} tint={PALETTE[5]} />
                </div>

                <div className="mt-6 grid gap-4 lg:grid-cols-2">
                    <Panel title="Reach (last 30 days)"><TrendChart data={reach} /></Panel>
                    <Panel title="Revenue (last 30 days)"><RevenueBars data={revenue} /></Panel>
                </div>

                <div className="mt-4 grid gap-4 lg:grid-cols-2">
                    <Panel title="Top events by revenue"><BarsChart data={topEvents} color={PALETTE[6]} height={260} /></Panel>
                    <Panel title="Audience age"><BarsChart data={demographics.age} color={PALETTE[2]} height={260} /></Panel>
                    <Panel title="Audience gender"><DonutChart data={demographics.gender} /></Panel>
                    <Panel title="Traffic sources"><DonutChart data={demographics.source} /></Panel>
                </div>

                {/* Advanced events table — scales past the old dropdown */}
                <section className="mt-8 rounded-2xl border border-border bg-card shadow-sm">
                    <div className="flex flex-col gap-3 border-b border-border p-4 sm:flex-row sm:items-center sm:justify-between">
                        <h2 className="flex items-center gap-2 text-sm font-semibold"><ChartColumn className="size-4" /> All events <span className="text-muted-foreground">({events.total.toLocaleString()})</span></h2>
                        <div className="flex items-center gap-2">
                            <form onSubmit={search} className="relative">
                                <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                                <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search events…" className="h-9 w-44 rounded-lg border border-input bg-card pl-8 pr-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20 sm:w-56" />
                            </form>
                            <Button asChild variant="outline" size="sm"><a href={exportUrl}><Download className="size-4" /> Export CSV</a></Button>
                        </div>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[720px] text-sm">
                            <thead className="border-b border-border text-xs text-muted-foreground">
                                <tr>
                                    <Th label="Event" k="title" activeSort={filters.sort} onSort={sortBy} />
                                    <Th label="Status" />
                                    <Th label="Date" />
                                    <Th label="Impressions" k="impressions" activeSort={filters.sort} onSort={sortBy} className="text-right" />
                                    <Th label="Clicks" className="text-right" />
                                    <Th label="CTR" className="text-right" />
                                    <Th label="Sold" k="sold" activeSort={filters.sort} onSort={sortBy} className="text-right" />
                                    <Th label="Revenue" k="revenue" activeSort={filters.sort} onSort={sortBy} className="text-right" />
                                    <th className="px-3 py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                {events.data.length === 0 && (
                                    <tr><td colSpan={9} className="px-3 py-10 text-center text-muted-foreground">No events match your search.</td></tr>
                                )}
                                {events.data.map((e) => (
                                    <tr key={e.slug} className="border-b border-border/60 last:border-0 hover:bg-muted/40">
                                        <td className="max-w-[220px] truncate px-3 py-2 font-medium">{e.title}</td>
                                        <td className="px-3 py-2"><span className="rounded-full bg-muted px-2 py-0.5 text-xs capitalize text-muted-foreground">{e.status}</span></td>
                                        <td className="whitespace-nowrap px-3 py-2 text-muted-foreground">{e.when ?? '—'}</td>
                                        <td className="px-3 py-2 text-right tabular-nums">{e.impressions.toLocaleString()}</td>
                                        <td className="px-3 py-2 text-right tabular-nums">{e.clicks.toLocaleString()}</td>
                                        <td className="px-3 py-2 text-right tabular-nums">{e.ctr}%</td>
                                        <td className="px-3 py-2 text-right tabular-nums">{e.sold.toLocaleString()}</td>
                                        <td className="px-3 py-2 text-right font-medium tabular-nums">{rm(e.revenue)}</td>
                                        <td className="px-3 py-2 text-right"><Button asChild size="sm" variant="outline"><Link href={`/admin/analytics/${e.slug}`}>View analytics</Link></Button></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {(events.prev_page_url || events.next_page_url) && (
                        <div className="flex items-center justify-between gap-2 border-t border-border p-3 text-sm">
                            <span className="text-muted-foreground">Page {events.current_page} of {events.last_page}</span>
                            <div className="flex gap-2">
                                <Button asChild variant="outline" size="sm" disabled={!events.prev_page_url}>{events.prev_page_url ? <Link href={events.prev_page_url} preserveScroll>← Prev</Link> : <span>← Prev</span>}</Button>
                                <Button asChild variant="outline" size="sm" disabled={!events.next_page_url}>{events.next_page_url ? <Link href={events.next_page_url} preserveScroll>Next →</Link> : <span>Next →</span>}</Button>
                            </div>
                        </div>
                    )}
                </section>
            </div>
        </>
    );
}

AdminAnalytics.layout = {
    breadcrumbs: [{ title: 'Analytics', href: '/admin/analytics' }],
};
