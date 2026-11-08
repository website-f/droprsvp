import { Link } from '@inertiajs/react';
import { CalendarClock, CheckCircle2, ExternalLink } from 'lucide-react';
import { cn } from '@/lib/utils';

export interface FeeRate { percent: number; flat: number; label: string; custom: boolean }

export interface EventStatement {
    orders: number; paying_orders: number; refunded_orders: number;
    sales: number; discounts: number; tax: number; refunds: number; net_sales: number;
    fees: number; effective_rate: number; organizer_share: number;
    fee: FeeRate; released: boolean; releases_on: string | null;
    ticket_types: { name: string; tickets: number; sales: number; fees: number; net: number }[];
}

export interface OrganizerStatement {
    name: string; email: string; events: number;
    earned: number; paid_out: number; requested: number; available: number; clearing: number; owed: number;
    fee: FeeRate;
    payouts: { reference: string; amount: number; status: string; method: string | null; requested_on: string | null; paid_on: string | null }[];
}

const rm = (n: number) => `RM ${n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

/** One line of a statement: label (and how it was worked out) on the left, amount on the right. */
function Line({ label, hint, amount, sign, strong, muted }: { label: string; hint?: string; amount: number; sign?: '−' | '='; strong?: boolean; muted?: boolean }) {
    return (
        <div className={cn('flex items-baseline justify-between gap-4 py-2', strong && 'border-t border-border pt-3', muted && 'text-muted-foreground')}>
            <div className="min-w-0">
                <div className={cn('text-sm', strong && 'font-semibold')}>
                    {sign && <span className="mr-1.5 inline-block w-3 text-muted-foreground">{sign}</span>}
                    {label}
                </div>
                {hint && <div className={cn('text-xs text-muted-foreground', sign && 'ml-[1.125rem]')}>{hint}</div>}
            </div>
            <div className={cn('shrink-0 text-right text-sm tabular-nums', strong && 'text-base font-bold')}>{sign === '−' && amount > 0 ? `− ${rm(amount)}` : rm(amount)}</div>
        </div>
    );
}

const STATUS: Record<string, string> = {
    paid: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    pending: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
    processing: 'bg-sky-500/15 text-sky-700 dark:text-sky-300',
};

/**
 * What an event took and what the organizer is owed, as a worked calculation
 * an admin can check line by line — sales, refunds, the platform's fee and the
 * organizer's share for this event, then where their payout stands across
 * every event they host.
 */
export function MoneyStatement({ event, organizer }: { event: EventStatement; organizer: OrganizerStatement | null }) {
    const salesHint = [
        `${event.orders} order${event.orders === 1 ? '' : 's'}`,
        event.discounts > 0 ? `after ${rm(event.discounts)} of discounts` : null,
        event.tax > 0 ? `includes ${rm(event.tax)} tax` : null,
    ].filter(Boolean).join(' · ');

    return (
        <section className="mt-6">
            <div className="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                <h2 className="text-base font-semibold">Money &amp; payout</h2>
                <span className="text-xs text-muted-foreground">All time · not affected by the date filter</span>
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                {/* ---- This event ---------------------------------------- */}
                <div className="rounded-2xl border border-border bg-card p-5 shadow-sm">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h3 className="text-sm font-semibold">This event</h3>
                        {event.releases_on || event.released ? (
                            <span className={cn('inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium', event.released ? STATUS.paid : STATUS.pending)}>
                                {event.released ? <CheckCircle2 className="size-3.5" /> : <CalendarClock className="size-3.5" />}
                                {event.released ? 'Released for payout' : `Held until ${event.releases_on}`}
                            </span>
                        ) : null}
                    </div>

                    <div className="mt-3 divide-y-0">
                        <Line label="Ticket sales" hint={salesHint} amount={event.sales} />
                        <Line sign="−" label="Refunds" hint={event.refunded_orders > 0 ? `${event.refunded_orders} order${event.refunded_orders === 1 ? '' : 's'} fully refunded` : undefined} amount={event.refunds} muted={event.refunds === 0} />
                        <Line sign="=" label="Net sales" amount={event.net_sales} strong />
                        <Line
                            sign="−"
                            label={`Platform fee${event.fee.custom ? ' (custom rate)' : ''}`}
                            hint={`${event.fee.label} per paid order · ${event.paying_orders} paid order${event.paying_orders === 1 ? '' : 's'} · works out to ${event.effective_rate}% of net sales`}
                            amount={event.fees}
                        />
                        <Line sign="=" label="Organizer's share" hint="What this event owes the organizer" amount={event.organizer_share} strong />
                    </div>

                    {event.ticket_types.length > 0 && (
                        <div className="mt-4 overflow-x-auto">
                            <table className="w-full min-w-[28rem] text-sm">
                                <thead>
                                    <tr className="border-b border-border text-left text-xs text-muted-foreground">
                                        <th className="py-2 pr-3 font-medium">Ticket type</th>
                                        <th className="py-2 pr-3 text-right font-medium">Tickets</th>
                                        <th className="py-2 pr-3 text-right font-medium">Sales</th>
                                        <th className="py-2 pr-3 text-right font-medium">Fee</th>
                                        <th className="py-2 text-right font-medium">Organizer gets</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {event.ticket_types.map((t) => (
                                        <tr key={t.name} className="border-b border-border/60 last:border-0">
                                            <td className="py-2 pr-3">{t.name}</td>
                                            <td className="py-2 pr-3 text-right tabular-nums">{t.tickets}</td>
                                            <td className="py-2 pr-3 text-right tabular-nums">{rm(t.sales)}</td>
                                            <td className="py-2 pr-3 text-right tabular-nums text-muted-foreground">{rm(t.fees)}</td>
                                            <td className="py-2 text-right font-medium tabular-nums">{rm(t.net)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            <p className="mt-2 text-xs text-muted-foreground">Paid orders only, before refunds. The fee is charged per order and shared across its tickets by price.</p>
                        </div>
                    )}
                </div>

                {/* ---- The organizer's payout ------------------------------ */}
                {organizer && (
                    <div className="rounded-2xl border border-border bg-card p-5 shadow-sm">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <div className="min-w-0">
                                <h3 className="truncate text-sm font-semibold">Payout to {organizer.name}</h3>
                                <p className="text-xs text-muted-foreground">Across all {organizer.events} of their event{organizer.events === 1 ? '' : 's'} · {organizer.fee.label}{organizer.fee.custom ? ' (custom rate)' : ''}</p>
                            </div>
                            <Link href="/admin/payouts" className="inline-flex items-center gap-1 text-xs font-medium text-muted-foreground hover:text-foreground">Payouts <ExternalLink className="size-3" /></Link>
                        </div>

                        <div className="mt-3">
                            <Line label="Earned" hint="Every event's organizer share, after refunds and fees" amount={organizer.earned} />
                            <Line sign="−" label="Already paid out" amount={organizer.paid_out} muted={organizer.paid_out === 0} />
                            <Line sign="=" label="Still owed to the organizer" amount={organizer.owed} strong />
                        </div>

                        <div className="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-3">
                            <div className="rounded-xl border border-border p-3">
                                <div className="text-xs text-muted-foreground">Requested, to pay</div>
                                <div className="mt-1 font-semibold tabular-nums">{rm(organizer.requested)}</div>
                                <div className="text-[11px] text-muted-foreground">Waiting on you</div>
                            </div>
                            <div className="rounded-xl border border-border p-3">
                                <div className="text-xs text-muted-foreground">Ready to request</div>
                                <div className="mt-1 font-semibold tabular-nums">{rm(organizer.available)}</div>
                                <div className="text-[11px] text-muted-foreground">Events have ended</div>
                            </div>
                            <div className="rounded-xl border border-border p-3">
                                <div className="text-xs text-muted-foreground">Still clearing</div>
                                <div className="mt-1 font-semibold tabular-nums">{rm(organizer.clearing)}</div>
                                <div className="text-[11px] text-muted-foreground">Released when events end</div>
                            </div>
                        </div>

                        <div className="mt-4">
                            <div className="mb-1 text-xs font-medium uppercase tracking-wide text-muted-foreground">Recent payouts</div>
                            {organizer.payouts.length === 0 ? (
                                <p className="text-sm text-muted-foreground">No payouts yet.</p>
                            ) : (
                                <ul className="divide-y divide-border/60">
                                    {organizer.payouts.map((p) => (
                                        <li key={p.reference} className="flex items-center justify-between gap-3 py-2 text-sm">
                                            <div className="min-w-0">
                                                <div className="font-mono text-xs">{p.reference}</div>
                                                <div className="text-xs text-muted-foreground">
                                                    {p.status === 'paid' ? `Paid ${p.paid_on ?? ''}${p.method ? ` · ${p.method}` : ''}` : `Requested ${p.requested_on ?? ''}`}
                                                </div>
                                            </div>
                                            <div className="flex shrink-0 items-center gap-2">
                                                <span className={cn('rounded-full px-2 py-0.5 text-[11px] font-medium capitalize', STATUS[p.status] ?? 'bg-muted text-muted-foreground')}>{p.status}</span>
                                                <span className="tabular-nums">{rm(p.amount)}</span>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </div>
                )}
            </div>
        </section>
    );
}
