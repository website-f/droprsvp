import { Head, Link } from '@inertiajs/react';
import { CalendarDays, ChartColumn, Eye, MousePointerClick, Ticket, Wallet } from 'lucide-react';
import { PALETTE, RevenueBars, TrendChart } from '@/components/charts';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

interface Reach { date: string; impressions: number; clicks: number }
interface EventRow { slug: string; title: string; status: string; impressions: number; sold: number; revenue: number }
interface Props {
    kpis: { events: number; impressions: number; clicks: number; tickets: number; revenue: number };
    reach: Reach[];
    revenue: { date: string; revenue: number }[];
    events: EventRow[];
}

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

export default function HostAnalytics({ kpis, reach, revenue, events }: Props) {
    return (
        <>
            <Head title="Analytics" />
            <div className="mx-auto w-full max-w-5xl flex-1 p-4">
                {/* Banner */}
                <div className="mb-6 flex flex-col gap-2 rounded-2xl border border-border bg-gradient-to-br from-foreground/[0.04] to-transparent p-5 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex items-center gap-3">
                        <span className="flex size-10 items-center justify-center rounded-xl bg-foreground text-background"><ChartColumn className="size-5" /></span>
                        <div>
                            <h1 className="text-xl font-bold tracking-tight">Analytics across all your events</h1>
                            <p className="text-sm text-muted-foreground">Your combined reach, sales and revenue. Open any event below for its own detailed report.</p>
                        </div>
                    </div>
                    <Button asChild variant="outline" className="shrink-0"><Link href="/host/events"><CalendarDays className="size-4" /> Manage events</Link></Button>
                </div>

                {/* Aggregate KPIs */}
                <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
                    <Kpi icon={CalendarDays} label="Events" value={kpis.events.toLocaleString()} tint={PALETTE[0]} />
                    <Kpi icon={Eye} label="Impressions" value={kpis.impressions.toLocaleString()} tint={PALETTE[5]} />
                    <Kpi icon={MousePointerClick} label="Clicks" value={kpis.clicks.toLocaleString()} tint={PALETTE[2]} />
                    <Kpi icon={Ticket} label="Tickets sold" value={kpis.tickets.toLocaleString()} tint={PALETTE[3]} />
                    <Kpi icon={Wallet} label="Revenue" value={`RM ${kpis.revenue.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`} tint={PALETTE[6]} />
                </div>

                <div className="mt-6 grid gap-4 lg:grid-cols-2">
                    <Panel title="Reach (last 30 days)"><TrendChart data={reach} /></Panel>
                    <Panel title="Revenue (last 30 days)"><RevenueBars data={revenue} /></Panel>
                </div>

                {/* Per-event list */}
                <section className="mt-6 rounded-2xl border border-border bg-card shadow-sm">
                    <h2 className="border-b border-border px-5 py-4 text-sm font-semibold">Per-event analytics</h2>
                    {events.length === 0 ? (
                        <p className="px-5 py-10 text-center text-sm text-muted-foreground">You haven't created any events yet.</p>
                    ) : (
                        <ul className="divide-y divide-border">
                            {events.map((e) => (
                                <li key={e.slug} className="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                                    <div className="min-w-0">
                                        <div className="flex items-center gap-2">
                                            <span className="truncate font-medium">{e.title}</span>
                                            <Badge variant={e.status === 'published' ? 'default' : 'secondary'} className="shrink-0 capitalize">{e.status}</Badge>
                                        </div>
                                        <div className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                                            <span className="flex items-center gap-1"><Eye className="size-3.5" /> {e.impressions.toLocaleString()}</span>
                                            <span className="flex items-center gap-1"><Ticket className="size-3.5" /> {e.sold.toLocaleString()} sold</span>
                                            <span className="flex items-center gap-1"><Wallet className="size-3.5" /> RM {e.revenue.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                                        </div>
                                    </div>
                                    <Button asChild variant="outline" size="sm" className="shrink-0">
                                        <Link href={`/host/events/${e.slug}/analytics`}><ChartColumn className="size-3.5" /> View analytics</Link>
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}

HostAnalytics.layout = {
    breadcrumbs: [{ title: 'Analytics', href: '/host/analytics' }],
};
