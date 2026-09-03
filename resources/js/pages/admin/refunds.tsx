import { Head, Link, router } from '@inertiajs/react';
import { CircleDollarSign, Clock, Undo2, XCircle } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

interface RefundRow {
    id: number; status: string; amount: number; approved_amount: number | null; reference: string | null;
    event: string | null; organizer: string | null; requester: string; when: string | null; decided: string | null;
}
interface Paginated { data: RefundRow[]; prev_page_url: string | null; next_page_url: string | null; current_page: number; last_page: number }
interface Props { requests: Paginated; filters: { status: string }; stats: { pending: number; approved: number; declined: number; refunded_total: number } }

const rm = (n: number) => `RM ${n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

function Stat({ icon: Icon, label, value, tint }: { icon: typeof Clock; label: string; value: string; tint: string }) {
    return (
        <div className="rounded-2xl border border-border bg-card p-4 shadow-sm">
            <span className="flex size-9 items-center justify-center rounded-xl" style={{ backgroundColor: `${tint}1f`, color: tint }}><Icon className="size-4" /></span>
            <div className="mt-3 text-2xl font-bold tabular-nums tracking-tight">{value}</div>
            <div className="text-xs text-muted-foreground">{label}</div>
        </div>
    );
}

export default function AdminRefunds({ requests, filters, stats }: Props) {
    const go = (status: string) => router.get('/admin/refunds', status ? { status } : {}, { preserveScroll: true, preserveState: true });
    const badge = (s: string) => s === 'approved' ? <Badge>Approved</Badge> : s === 'declined' ? <Badge variant="secondary">Declined</Badge> : <Badge variant="outline">Pending</Badge>;

    const TABS = [['', 'All'], ['pending', 'Pending'], ['approved', 'Approved'], ['declined', 'Declined']];

    return (
        <>
            <Head title="Refunds" />
            <div className="mx-auto w-full max-w-5xl flex-1 p-4">
                <div className="mb-6">
                    <h1 className="text-2xl font-bold tracking-tight">Refunds</h1>
                    <p className="text-sm text-muted-foreground">Every refund request across the platform — reconcile against Finance.</p>
                </div>

                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <Stat icon={Clock} label="Pending" value={stats.pending.toLocaleString()} tint="#f5a524" />
                    <Stat icon={Undo2} label="Approved" value={stats.approved.toLocaleString()} tint="#22c55e" />
                    <Stat icon={XCircle} label="Declined" value={stats.declined.toLocaleString()} tint="#ff6584" />
                    <Stat icon={CircleDollarSign} label="Total refunded" value={rm(stats.refunded_total)} tint="#6c63ff" />
                </div>

                <div className="mt-6 mb-4 flex flex-wrap gap-2">
                    {TABS.map(([v, l]) => (
                        <button key={v} onClick={() => go(v)} className={`rounded-full border px-4 py-1.5 text-sm transition-colors ${filters.status === v ? 'border-foreground bg-foreground text-background' : 'border-border hover:border-foreground/40'}`}>{l}</button>
                    ))}
                </div>

                <div className="overflow-x-auto rounded-2xl border border-border">
                    <table className="w-full min-w-[720px] text-sm">
                        <thead className="bg-muted/50 text-left text-xs uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th className="px-4 py-3 font-medium">Request</th>
                                <th className="px-4 py-3 font-medium">Event · Organizer</th>
                                <th className="px-4 py-3 text-right font-medium">Requested</th>
                                <th className="px-4 py-3 text-right font-medium">Refunded</th>
                                <th className="px-4 py-3 font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-border">
                            {requests.data.length === 0 ? (
                                <tr><td colSpan={5} className="px-4 py-10 text-center text-muted-foreground">No refund requests.</td></tr>
                            ) : requests.data.map((r) => (
                                <tr key={r.id} className="hover:bg-muted/30">
                                    <td className="px-4 py-3">
                                        <div className="font-medium">{r.requester}</div>
                                        <div className="text-xs text-muted-foreground">{r.reference} · {r.when}</div>
                                    </td>
                                    <td className="px-4 py-3">
                                        <div className="truncate">{r.event ?? '—'}</div>
                                        <div className="text-xs text-muted-foreground">{r.organizer ?? '—'}</div>
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums">{rm(r.amount)}</td>
                                    <td className="px-4 py-3 text-right font-medium tabular-nums">{r.approved_amount !== null ? rm(r.approved_amount) : '—'}</td>
                                    <td className="px-4 py-3">{badge(r.status)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {(requests.prev_page_url || requests.next_page_url) && (
                    <div className="mt-6 flex items-center justify-between gap-2 text-sm">
                        <span className="text-muted-foreground">Page {requests.current_page} of {requests.last_page}</span>
                        <div className="flex gap-2">
                            <Button asChild variant="outline" size="sm" disabled={!requests.prev_page_url}>{requests.prev_page_url ? <Link href={requests.prev_page_url} preserveScroll>← Prev</Link> : <span>← Prev</span>}</Button>
                            <Button asChild variant="outline" size="sm" disabled={!requests.next_page_url}>{requests.next_page_url ? <Link href={requests.next_page_url} preserveScroll>Next →</Link> : <span>Next →</span>}</Button>
                        </div>
                    </div>
                )}
            </div>
        </>
    );
}

AdminRefunds.layout = { breadcrumbs: [{ title: 'Refunds', href: '/admin/refunds' }] };
