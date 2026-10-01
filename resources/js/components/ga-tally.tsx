import { CheckCircle2, Clock, Globe, Server, TriangleAlert } from 'lucide-react';

interface Bucket { orders: number; revenue: number }
export interface GaTallyData {
    configured: boolean;
    last_sync: { at: string; sent: number; failed: number } | null;
    paid: Bucket;
    browser: Bucket;
    server: Bucket;
    pending: Bucket;
    missed: Bucket;
}

const rm = (n: number) => `RM ${n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

/**
 * The books vs Google Analytics: every paid order in the window, and how (or
 * whether) GA heard about it. Reported-by-browser + reported-by-server should
 * equal the paid total once nothing is pending; that is the number GA's
 * purchase report should agree with.
 */
export function GaTally({ data, periodLabel }: { data: GaTallyData; periodLabel: string }) {
    const reported = data.browser.orders + data.server.orders;
    const inSync = reported === data.paid.orders;
    const last = data.last_sync ? new Date(data.last_sync.at) : null;

    const rows: { icon: typeof Globe; label: string; hint: string; b: Bucket; tone?: string }[] = [
        { icon: Globe, label: 'Sent from the confirmation page', hint: 'The buyer’s own browser, live', b: data.browser },
        { icon: Server, label: 'Sent by the server sync', hint: 'Tag blocked, tab closed, or paid in the bank app', b: data.server },
        { icon: Clock, label: 'Waiting to be sent', hint: 'Paid in the last few minutes, or retrying', b: data.pending, tone: data.pending.orders ? 'text-amber-600 dark:text-amber-400' : undefined },
        { icon: TriangleAlert, label: 'Too old to send', hint: 'Over 72 hours old with no report — GA can’t backdate them', b: data.missed, tone: data.missed.orders ? 'text-rose-600 dark:text-rose-400' : undefined },
    ];

    return (
        <section className="rounded-2xl border border-border bg-card p-5 shadow-sm">
            <div className="mb-4 flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h2 className="text-sm font-semibold">Google Analytics purchases · {periodLabel}</h2>
                    <p className="text-xs text-muted-foreground">Every paid order, and how GA heard about it. Each sale is sent once.</p>
                </div>
                <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium ${inSync ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400' : 'bg-amber-500/10 text-amber-700 dark:text-amber-400'}`}>
                    {inSync ? <CheckCircle2 className="size-3.5" /> : <Clock className="size-3.5" />}
                    {reported} of {data.paid.orders} sent to GA
                </span>
            </div>

            <div className="grid gap-2">
                <div className="flex items-baseline justify-between gap-3 border-b border-border pb-2 text-sm">
                    <span className="font-medium">Paid orders (the books)</span>
                    <span className="tabular-nums"><span className="font-semibold">{data.paid.orders}</span> · {rm(data.paid.revenue)}</span>
                </div>
                {rows.map(({ icon: Icon, label, hint, b, tone }) => (
                    <div key={label} className="flex items-start justify-between gap-3 text-sm">
                        <span className="flex min-w-0 items-start gap-2">
                            <Icon className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                            <span className="min-w-0">
                                <span className="block">{label}</span>
                                <span className="block text-xs text-muted-foreground">{hint}</span>
                            </span>
                        </span>
                        <span className={`shrink-0 text-right tabular-nums ${tone ?? ''}`}><span className="font-semibold">{b.orders}</span> · {rm(b.revenue)}</span>
                    </div>
                ))}
            </div>

            <p className="mt-3 text-xs text-muted-foreground">
                {data.configured
                    ? <>Server sync is on{last ? <> — last ran {last.toLocaleString()}{data.last_sync && data.last_sync.failed > 0 ? `, ${data.last_sync.failed} failed and will retry` : ''}</> : ' — it has not run yet (check the scheduler cron)'}.</>
                    : <>Server sync is <strong>off</strong>: add <code>GA_API_SECRET</code> to <code>.env</code>. Until then, sales whose browser blocks GA are never sent.</>}
                {' '}GA’s own purchase reports can lag up to a day behind; Realtime shows them within a minute.
            </p>
        </section>
    );
}
