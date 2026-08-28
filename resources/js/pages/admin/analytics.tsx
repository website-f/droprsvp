import { Head } from '@inertiajs/react';
import { CalendarCheck, CalendarDays, Eye, Ticket, Users, Wallet } from 'lucide-react';
import { BarsChart, DonutChart, PALETTE, RevenueBars, TrendChart } from '@/components/charts';

interface Slice { name: string; value: number }
interface Props {
    kpis: { events: number; published: number; users: number; tickets: number; revenue: number; impressions: number };
    reach: { date: string; impressions: number; clicks: number }[];
    revenue: { date: string; revenue: number }[];
    topEvents: Slice[];
    demographics: { gender: Slice[]; age: Slice[]; source: Slice[] };
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

export default function AdminAnalytics({ kpis, reach, revenue, topEvents, demographics }: Props) {
    return (
        <>
            <Head title="Analytics" />
            <div className="mx-auto w-full max-w-6xl flex-1 p-4">
                <h1 className="mb-1 text-2xl font-bold tracking-tight">Platform analytics</h1>
                <p className="mb-6 text-sm text-muted-foreground">Everything happening across DropRSVP.</p>

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
