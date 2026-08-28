import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Eye, MousePointerClick, Percent, Ticket, TrendingUp, Wallet } from 'lucide-react';
import { BarsChart, DonutChart, PALETTE, TrendChart } from '@/components/charts';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

interface Slice { name: string; value: number }
interface Props {
    event: { slug: string; title: string; status: string };
    kpis: { impressions: number; clicks: number; ctr: number; sold: number; revenue: number; conversion: number };
    trend: { date: string; impressions: number; clicks: number }[];
    demographics: { gender: Slice[]; age: Slice[]; city: Slice[]; source: Slice[] };
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

export default function EventAnalytics({ event, kpis, trend, demographics }: Props) {
    const hasAudience = demographics.gender.length + demographics.age.length + demographics.city.length + demographics.source.length > 0;

    return (
        <>
            <Head title={`Analytics · ${event.title}`} />
            <div className="mx-auto w-full max-w-5xl flex-1 p-4">
                <div className="mb-6 flex flex-wrap items-center gap-3">
                    <Button asChild variant="ghost" size="icon"><Link href="/host/events" aria-label="Back"><ArrowLeft className="size-4" /></Link></Button>
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">{event.title}</h1>
                        <p className="text-sm text-muted-foreground">Reach, sales and audience insights.</p>
                    </div>
                    <Badge variant={event.status === 'published' ? 'default' : 'secondary'} className="ml-auto capitalize">{event.status}</Badge>
                </div>

                {/* KPIs */}
                <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                    <Kpi icon={Eye} label="Impressions" value={kpis.impressions.toLocaleString()} tint={PALETTE[0]} />
                    <Kpi icon={MousePointerClick} label="Clicks" value={kpis.clicks.toLocaleString()} tint={PALETTE[1]} />
                    <Kpi icon={Percent} label="Click rate" value={`${kpis.ctr}%`} tint={PALETTE[2]} />
                    <Kpi icon={Ticket} label="Tickets sold" value={kpis.sold.toLocaleString()} tint={PALETTE[4]} />
                    <Kpi icon={TrendingUp} label="Conversion" value={`${kpis.conversion}%`} tint={PALETTE[3]} />
                    <Kpi icon={Wallet} label="Revenue" value={`RM ${kpis.revenue.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`} tint={PALETTE[6]} />
                </div>

                {/* Trend */}
                <div className="mt-6">
                    <Panel title="Reach over the last 30 days">
                        <TrendChart data={trend} />
                    </Panel>
                </div>

                {/* Audience */}
                <h2 className="mt-8 mb-3 text-lg font-semibold tracking-tight">Audience</h2>
                {!hasAudience ? (
                    <div className="rounded-2xl border border-dashed border-border p-10 text-center text-sm text-muted-foreground">
                        Audience insights appear once people buy tickets and share their details at checkout.
                    </div>
                ) : (
                    <div className="grid gap-4 md:grid-cols-2">
                        <Panel title="Gender"><DonutChart data={demographics.gender} /></Panel>
                        <Panel title="Age"><BarsChart data={demographics.age} color={PALETTE[2]} /></Panel>
                        <Panel title="Top cities"><BarsChart data={demographics.city} color={PALETTE[4]} /></Panel>
                        <Panel title="How they heard about it"><DonutChart data={demographics.source} /></Panel>
                    </div>
                )}
            </div>
        </>
    );
}

EventAnalytics.layout = {
    breadcrumbs: [{ title: 'Events', href: '/host/events' }, { title: 'Analytics', href: '#' }],
};
