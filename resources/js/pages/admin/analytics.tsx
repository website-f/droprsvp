import { Head, router } from '@inertiajs/react';
import { CalendarCheck, CalendarDays, Eye, MousePointerClick, Percent, Ticket, Users, Wallet } from 'lucide-react';
import { BarsChart, DonutChart, PALETTE, RevenueBars, TrendChart } from '@/components/charts';
import { AppSelect } from '@/components/ui/app-select';

interface Slice { name: string; value: number }
interface Reach { date: string; impressions: number; clicks: number }
interface EventBreakdown {
    event: { slug: string; title: string; status: string };
    kpis: { impressions: number; clicks: number; ctr: number; sold: number; revenue: number; conversion: number };
    trend: Reach[];
    demographics: { gender: Slice[]; age: Slice[]; city: Slice[]; source: Slice[] };
}
interface Props {
    kpis: { events: number; published: number; users: number; tickets: number; revenue: number; impressions: number };
    reach: Reach[];
    revenue: { date: string; revenue: number }[];
    topEvents: Slice[];
    demographics: { gender: Slice[]; age: Slice[]; source: Slice[] };
    events: { slug: string; title: string }[];
    selectedSlug: string | null;
    selected: EventBreakdown | null;
}

const ALL = 'all';

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

export default function AdminAnalytics({ kpis, reach, revenue, topEvents, demographics, events, selectedSlug, selected }: Props) {
    const pickEvent = (slug: string) =>
        router.get('/admin/analytics', slug === ALL ? {} : { event: slug }, { preserveScroll: true, preserveState: false });

    return (
        <>
            <Head title="Analytics" />
            <div className="mx-auto w-full max-w-6xl flex-1 p-4">
                <div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h1 className="mb-1 text-2xl font-bold tracking-tight">Platform analytics</h1>
                        <p className="text-sm text-muted-foreground">Everything happening across DropRSVP.</p>
                    </div>
                    <div className="sm:w-72">
                        <label className="mb-1 block text-xs font-medium text-muted-foreground">Drill into an event</label>
                        <AppSelect
                            aria-label="Event"
                            value={selectedSlug ?? ALL}
                            onChange={pickEvent}
                            options={[{ value: ALL, label: 'Platform overview' }, ...events.map((e) => ({ value: e.slug, label: e.title }))]}
                        />
                    </div>
                </div>

                {/* Per-event drill-down */}
                {selected && (
                    <section className="mb-8 rounded-2xl border-2 border-foreground/10 bg-muted/30 p-5">
                        <h2 className="mb-4 flex items-center gap-2 text-lg font-bold tracking-tight">
                            <CalendarDays className="size-5" /> {selected.event.title}
                        </h2>
                        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                            <Kpi icon={Eye} label="Impressions" value={selected.kpis.impressions.toLocaleString()} tint={PALETTE[5]} />
                            <Kpi icon={MousePointerClick} label="Clicks" value={selected.kpis.clicks.toLocaleString()} tint={PALETTE[0]} />
                            <Kpi icon={Percent} label="CTR" value={`${selected.kpis.ctr}%`} tint={PALETTE[2]} />
                            <Kpi icon={Ticket} label="Tickets sold" value={selected.kpis.sold.toLocaleString()} tint={PALETTE[3]} />
                            <Kpi icon={Percent} label="Conversion" value={`${selected.kpis.conversion}%`} tint={PALETTE[4]} />
                            <Kpi icon={Wallet} label="Revenue" value={`RM ${selected.kpis.revenue.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`} tint={PALETTE[6]} />
                        </div>
                        <div className="mt-4 grid gap-4 lg:grid-cols-2">
                            <Panel title="Reach (last 30 days)"><TrendChart data={selected.trend} /></Panel>
                            <Panel title="Audience age"><BarsChart data={selected.demographics.age} color={PALETTE[2]} height={260} /></Panel>
                            <Panel title="Top cities"><BarsChart data={selected.demographics.city} color={PALETTE[0]} height={260} /></Panel>
                            <Panel title="Traffic sources"><DonutChart data={selected.demographics.source} /></Panel>
                            <Panel title="Audience gender"><DonutChart data={selected.demographics.gender} /></Panel>
                        </div>
                    </section>
                )}

                <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                    <Kpi icon={CalendarDays} label="Events" value={kpis.events.toLocaleString()} tint={PALETTE[0]} />
                    <Kpi icon={CalendarCheck} label="Published" value={kpis.published.toLocaleString()} tint={PALETTE[2]} />
                    <Kpi icon={Users} label="Users" value={kpis.users.toLocaleString()} tint={PALETTE[4]} />
                    <Kpi icon={Ticket} label="Tickets sold" value={kpis.tickets.toLocaleString()} tint={PALETTE[3]} />
                    <Kpi icon={Wallet} label="Revenue" value={`RM ${kpis.revenue.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`} tint={PALETTE[6]} />
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
            </div>
        </>
    );
}

AdminAnalytics.layout = {
    breadcrumbs: [{ title: 'Analytics', href: '/admin/analytics' }],
};
