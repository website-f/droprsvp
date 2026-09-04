import { Head, Link } from '@inertiajs/react';
import { Banknote, CircleDollarSign, Clock, Landmark, TrendingUp, Wallet } from 'lucide-react';
import { Button } from '@/components/ui/button';

interface Balance {
    gross: number; fee_label: string;
    net: number; withdrawn: number; available: number; pending_clearance: number;
}
interface EventRow { slug: string; title: string; status: string; sold: number; gross: number; fee: number; net: number }
interface Props { balance: Balance; feeLabel: string; events: EventRow[] }

const rm = (n: number) => `RM ${n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

function Stat({ icon: Icon, label, value, tint, hint }: { icon: typeof Wallet; label: string; value: string; tint: string; hint?: string }) {
    return (
        <div className="rounded-2xl border border-border bg-card p-4 shadow-sm">
            <span className="flex size-9 items-center justify-center rounded-xl" style={{ backgroundColor: `${tint}1f`, color: tint }}><Icon className="size-4" /></span>
            <div className="mt-3 text-2xl font-bold tabular-nums tracking-tight">{value}</div>
            <div className="text-xs text-muted-foreground">{label}</div>
            {hint && <div className="mt-0.5 text-[11px] text-muted-foreground">{hint}</div>}
        </div>
    );
}

export default function HostFinance({ balance, feeLabel, events }: Props) {
    return (
        <>
            <Head title="Finance" />
            <div className="mx-auto w-full max-w-5xl flex-1 p-4">
                <div className="mb-6 flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Finance</h1>
                        <p className="text-sm text-muted-foreground">Your ticket revenue and what’s available to pay out. Buyers pay the platform fee ({feeLabel}) at checkout — it isn’t deducted from you.</p>
                    </div>
                    <Button asChild variant="outline"><Link href="/host/payouts"><Landmark className="size-4" /> Payouts</Link></Button>
                </div>

                <div className="grid grid-cols-2 gap-4 lg:grid-cols-3">
                    <Stat icon={TrendingUp} label="Ticket revenue (yours)" value={rm(balance.net)} tint="#6c63ff" />
                    <Stat icon={CircleDollarSign} label="Buyer fees collected" value={rm(events.reduce((s, e) => s + e.fee, 0))} tint="#ff6584" hint="Paid by buyers to the platform" />
                    <Stat icon={Wallet} label="Net earnings" value={rm(balance.net)} tint="#22c55e" />
                    <Stat icon={Banknote} label="Available to withdraw" value={rm(balance.available)} tint="#2ec4b6" />
                    <Stat icon={Clock} label="Held until events end" value={rm(balance.pending_clearance)} tint="#f5a524" hint="Clears once those events have taken place" />
                    <Stat icon={Landmark} label="Paid out / requested" value={rm(balance.withdrawn)} tint="#3b82f6" />
                </div>

                <div className="mt-8">
                    <h2 className="mb-3 text-sm font-semibold">Revenue by event</h2>
                    {events.length === 0 ? (
                        <p className="rounded-2xl border border-dashed border-border py-16 text-center text-sm text-muted-foreground">No revenue yet.</p>
                    ) : (
                        <div className="overflow-x-auto rounded-2xl border border-border">
                            <table className="w-full min-w-[640px] text-sm">
                                <thead className="bg-muted/50 text-left text-xs uppercase tracking-wide text-muted-foreground">
                                    <tr>
                                        <th className="px-4 py-3 font-medium">Event</th>
                                        <th className="px-4 py-3 text-right font-medium">Sold</th>
                                        <th className="px-4 py-3 text-right font-medium">Gross</th>
                                        <th className="px-4 py-3 text-right font-medium">Fee</th>
                                        <th className="px-4 py-3 text-right font-medium">Net</th>
                                        <th className="px-4 py-3" />
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-border">
                                    {events.map((e) => (
                                        <tr key={e.slug} className="hover:bg-muted/30">
                                            <td className="px-4 py-3">
                                                <div className="font-medium">{e.title}</div>
                                                <div className="text-xs capitalize text-muted-foreground">{e.status}</div>
                                            </td>
                                            <td className="px-4 py-3 text-right tabular-nums">{e.sold.toLocaleString()}</td>
                                            <td className="px-4 py-3 text-right tabular-nums">{rm(e.gross)}</td>
                                            <td className="px-4 py-3 text-right tabular-nums text-muted-foreground">− {rm(e.fee)}</td>
                                            <td className="px-4 py-3 text-right font-semibold tabular-nums">{rm(e.net)}</td>
                                            <td className="px-4 py-3 text-right">
                                                <Link href={`/host/invoices/events/${e.slug}`} className="text-xs font-medium text-muted-foreground hover:text-foreground">Invoices →</Link>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    <p className="mt-3 text-xs text-muted-foreground">Net = gross − the platform fee ({feeLabel}). Available reflects matured events only; upcoming-event takings are held until the event has happened.</p>
                </div>
            </div>
        </>
    );
}

HostFinance.layout = {
    breadcrumbs: [{ title: 'Finance', href: '/host/finance' }],
};
