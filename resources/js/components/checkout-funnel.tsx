import { Link } from '@inertiajs/react';
import { ArrowRight, MailWarning, ShoppingCart, UserRoundX, Wallet } from 'lucide-react';
import { PALETTE } from '@/components/charts';

export interface FunnelStep { key: string; label: string; value: number }
export interface FunnelSummary {
    funnel: FunnelStep[];
    started: number;
    details: number;
    paid: number;
    in_progress: number;
    checkout_conversion: number;
    abandonment_rate: number;
    abandoned: {
        orders: number;
        started: number;
        details: number;
        recovered: number;
        people: number;
        reachable: number;
        anonymous: number;
        lost_value: number;
        tickets: number;
    };
}

const rm = (n: number) => `RM ${n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const COLORS = [PALETTE[5], PALETTE[0], PALETTE[1], PALETTE[6]];

/**
 * Event page view → checkout → details → paid, with the drop between each step,
 * and the abandoned-checkout numbers underneath. Shared by the platform and
 * per-event analytics pages; `abandonedHref` links through to the contact list.
 */
export function CheckoutFunnel({ data, abandonedHref, title = 'Checkout funnel' }: { data: FunnelSummary; abandonedHref?: string; title?: string }) {
    // The views step is usually an order of magnitude larger than the rest, so
    // bars are scaled to "checkout started" when there are views, keeping the
    // later steps readable; the views bar is simply drawn full.
    const steps = data.funnel;
    const base = Math.max(1, ...steps.filter((s) => s.key !== 'views').map((s) => s.value));
    const a = data.abandoned;

    return (
        <section className="rounded-2xl border border-border bg-card p-5 shadow-sm">
            <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-sm font-semibold">{title}</h2>
                {abandonedHref && <Link href={abandonedHref} className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline">
                    Abandoned checkouts &amp; contacts <ArrowRight className="size-3.5" />
                </Link>}
            </div>

            <div className="grid gap-2.5">
                {steps.map((s, i) => {
                    const prev = i > 0 ? steps[i - 1].value : null;
                    const pct = s.key === 'views' ? 100 : Math.round((s.value / base) * 100);
                    const drop = prev && prev > 0 && s.key !== 'views' && steps[i - 1].key !== 'views'
                        ? Math.round((1 - s.value / prev) * 100)
                        : null;

                    return (
                        <div key={s.key} className="grid grid-cols-[9.5rem_minmax(0,1fr)_4.5rem] items-center gap-3 text-sm max-sm:grid-cols-[7rem_minmax(0,1fr)_3.5rem]">
                            <span className="truncate text-muted-foreground">{s.label}</span>
                            <div className="h-6 overflow-hidden rounded-md bg-muted/60">
                                <div className="h-full rounded-md transition-all" style={{ width: `${Math.max(pct, s.value > 0 ? 2 : 0)}%`, backgroundColor: COLORS[i % COLORS.length] }} />
                            </div>
                            <span className="text-right tabular-nums">
                                <span className="font-semibold">{s.value.toLocaleString()}</span>
                                {drop !== null && drop > 0 && <span className="block text-[10px] text-rose-600 dark:text-rose-400">−{drop}%</span>}
                            </span>
                        </div>
                    );
                })}
            </div>

            <p className="mt-3 text-xs text-muted-foreground">
                {data.checkout_conversion}% of checkouts were paid · {data.abandonment_rate}% abandoned
                {data.in_progress > 0 && <> · {data.in_progress} in progress right now</>}
            </p>

            <div className="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <Tile icon={UserRoundX} tint={PALETTE[3]} label="People who didn’t pay" value={a.people.toLocaleString()} hint={`${a.orders.toLocaleString()} unpaid checkouts`} />
                <Tile icon={MailWarning} tint={PALETTE[1]} label="Filled in details, didn’t pay" value={a.details.toLocaleString()} hint={`${a.started.toLocaleString()} left before the form`} />
                <Tile icon={ShoppingCart} tint={PALETTE[0]} label="Reachable for follow-up" value={a.reachable.toLocaleString()} hint={`${a.anonymous.toLocaleString()} anonymous guests · ${a.recovered} came back & paid`} />
                <Tile icon={Wallet} tint={PALETTE[6]} label="Value left behind" value={rm(a.lost_value)} hint={`${a.tickets.toLocaleString()} tickets`} />
            </div>
        </section>
    );
}

function Tile({ icon: Icon, tint, label, value, hint }: { icon: typeof Wallet; tint: string; label: string; value: string; hint: string }) {
    return (
        <div className="rounded-xl border border-border p-3">
            <div className="flex items-center gap-2">
                <span className="flex size-7 shrink-0 items-center justify-center rounded-lg" style={{ backgroundColor: `${tint}1f`, color: tint }}><Icon className="size-3.5" /></span>
                <span className="text-lg font-bold tabular-nums">{value}</span>
            </div>
            <div className="mt-1 text-xs font-medium">{label}</div>
            <div className="text-[11px] text-muted-foreground">{hint}</div>
        </div>
    );
}
