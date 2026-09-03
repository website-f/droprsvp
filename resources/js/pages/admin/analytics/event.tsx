import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Eye, MousePointerClick, Percent, Ticket, Wallet } from 'lucide-react';
import { useState } from 'react';
import { AnalyticsToolbar, AudienceFilters } from '@/components/analytics-toolbar';
import type { AnalyticsPeriod } from '@/components/analytics-toolbar';
import { BarsChart, DonutChart, MetricToggle, PALETTE, TrendChart } from '@/components/charts';
import type { ReachMetric } from '@/components/charts';

interface Slice { name: string; value: number }
interface Reach { date: string; impressions: number; clicks: number }
interface Data {
    event: { slug: string; title: string; status: string };
    kpis: { impressions: number; clicks: number; ctr: number; sold: number; revenue: number; conversion: number };
    trend: Reach[];
    demographics: { gender: Slice[]; age: Slice[]; city: Slice[]; source: Slice[] };
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

export default function AdminEventAnalytics({ data, filters, cityOptions, sourceOptions }: { data: Data; filters: AnalyticsPeriod; cityOptions: string[]; sourceOptions: { value: string; label: string }[] }) {
    const [metric, setMetric] = useState<ReachMetric>('both');

    return (
        <>
            <Head title={`${data.event.title} — analytics`} />
            <div className="mx-auto w-full max-w-6xl flex-1 p-4">
                <Link href="/admin/analytics" className="mb-4 inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft className="size-4" /> Back to all events</Link>

                <div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div className="flex flex-wrap items-center gap-2">
                        <h1 className="text-2xl font-bold tracking-tight">{data.event.title}</h1>
                        <span className="rounded-full bg-muted px-2 py-0.5 text-xs capitalize text-muted-foreground">{data.event.status}</span>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <AudienceFilters path={`/admin/analytics/${data.event.slug}`} city={filters.city} source={filters.source} cities={cityOptions} sources={sourceOptions} />
                        <AnalyticsToolbar path={`/admin/analytics/${data.event.slug}`} filters={filters} />
                    </div>
                </div>

                <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                    <Kpi icon={Eye} label="Impressions" value={data.kpis.impressions.toLocaleString()} tint={PALETTE[5]} />
                    <Kpi icon={MousePointerClick} label="Clicks" value={data.kpis.clicks.toLocaleString()} tint={PALETTE[0]} />
                    <Kpi icon={Percent} label="CTR" value={`${data.kpis.ctr}%`} tint={PALETTE[2]} />
                    <Kpi icon={Ticket} label="Tickets sold" value={data.kpis.sold.toLocaleString()} tint={PALETTE[3]} />
                    <Kpi icon={Percent} label="Conversion" value={`${data.kpis.conversion}%`} tint={PALETTE[4]} />
                    <Kpi icon={Wallet} label="Revenue" value={rm(data.kpis.revenue)} tint={PALETTE[6]} />
                </div>

                <div className="mt-6 grid gap-4 lg:grid-cols-2">
                    <section className="rounded-2xl border border-border bg-card p-5 shadow-sm">
                        <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
                            <h2 className="text-sm font-semibold">Reach · {filters.periodLabel}</h2>
                            <MetricToggle value={metric} onChange={setMetric} />
                        </div>
                        <TrendChart data={data.trend} metric={metric} />
                    </section>
                    <Panel title="Audience age"><BarsChart data={data.demographics.age} color={PALETTE[2]} height={260} /></Panel>
                    <Panel title="Top cities"><BarsChart data={data.demographics.city} color={PALETTE[0]} height={260} /></Panel>
                    <Panel title="Traffic sources"><DonutChart data={data.demographics.source} /></Panel>
                    <Panel title="Audience gender"><DonutChart data={data.demographics.gender} /></Panel>
                </div>
            </div>
        </>
    );
}

AdminEventAnalytics.layout = {
    breadcrumbs: [{ title: 'Analytics', href: '/admin/analytics' }, { title: 'Event', href: '#' }],
};
