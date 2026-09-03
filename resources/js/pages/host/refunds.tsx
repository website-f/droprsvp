import { Head, Link, useForm } from '@inertiajs/react';
import { Check, Undo2, X } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';

interface RefundRow {
    id: number; status: string; amount: number; approved_amount: number | null; reason: string | null;
    decision_note: string | null; requester: string; reference: string | null; remaining: number; event: string | null; when: string | null;
}
interface Paginated { data: RefundRow[]; prev_page_url: string | null; next_page_url: string | null; current_page: number; last_page: number }
interface Props { requests: Paginated; pending: number }

const rm = (n: number) => `RM ${n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const input = 'h-10 w-full rounded-lg border border-input bg-card px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

export default function HostRefunds({ requests, pending }: Props) {
    const [approve, setApprove] = useState<RefundRow | null>(null);
    const [decline, setDecline] = useState<RefundRow | null>(null);
    const approveForm = useForm({ amount: '' });
    const declineForm = useForm({ note: '' });

    const openApprove = (r: RefundRow) => {
        approveForm.setData('amount', String(Math.min(r.amount, r.remaining).toFixed(2)));
        approveForm.clearErrors();
        setApprove(r);
    };
    const submitApprove = (e: React.FormEvent) => {
        e.preventDefault();

        if (!approve) {
return;
}

        approveForm.post(`/host/refunds/${approve.id}/approve`, { preserveScroll: true, onSuccess: () => setApprove(null) });
    };
    const submitDecline = (e: React.FormEvent) => {
        e.preventDefault();

        if (!decline) {
return;
}

        declineForm.post(`/host/refunds/${decline.id}/decline`, { preserveScroll: true, onSuccess: () => {
 declineForm.reset(); setDecline(null); 
} });
    };

    const badge = (s: string) => s === 'approved' ? <Badge>Approved</Badge> : s === 'declined' ? <Badge variant="secondary">Declined</Badge> : <Badge variant="outline">Pending</Badge>;

    return (
        <>
            <Head title="Refunds" />
            <div className="mx-auto w-full max-w-4xl flex-1 p-4">
                <div className="mb-6">
                    <h1 className="text-2xl font-bold tracking-tight">Refunds</h1>
                    <p className="text-sm text-muted-foreground">{pending > 0 ? `${pending} request${pending === 1 ? '' : 's'} awaiting your decision.` : 'Approve or decline refund requests from your attendees.'}</p>
                </div>

                {requests.data.length === 0 ? (
                    <div className="flex flex-col items-center justify-center rounded-2xl border border-dashed border-border py-16 text-center">
                        <span className="flex size-11 items-center justify-center rounded-full bg-muted text-muted-foreground"><Undo2 className="size-5" /></span>
                        <p className="mt-3 text-sm font-medium">No refund requests</p>
                        <p className="mt-1 text-xs text-muted-foreground">Requests from your attendees appear here.</p>
                    </div>
                ) : (
                    <ul className="grid gap-3">
                        {requests.data.map((r) => (
                            <li key={r.id} className="rounded-xl border border-border bg-card p-4">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-semibold tabular-nums">{rm(r.amount)}</span>
                                            {badge(r.status)}
                                            <span className="text-xs text-muted-foreground">{r.when}</span>
                                        </div>
                                        <div className="mt-0.5 text-sm">{r.requester} · <span className="text-muted-foreground">{r.event}</span></div>
                                        <div className="text-xs text-muted-foreground">{r.reference}</div>
                                        {r.reason && <p className="mt-2 rounded-lg bg-muted/50 px-3 py-2 text-sm text-foreground/80">“{r.reason}”</p>}
                                        {r.status === 'approved' && r.approved_amount !== null && <p className="mt-2 text-xs text-emerald-600 dark:text-emerald-400">Refunded {rm(r.approved_amount)}.</p>}
                                        {r.status === 'declined' && r.decision_note && <p className="mt-2 text-xs text-muted-foreground">Declined: {r.decision_note}</p>}
                                    </div>
                                    {r.status === 'pending' && (
                                        <div className="flex shrink-0 gap-2">
                                            <Button size="sm" onClick={() => openApprove(r)}><Check className="size-3.5" /> Approve</Button>
                                            <Button size="sm" variant="outline" onClick={() => {
 declineForm.reset(); declineForm.clearErrors(); setDecline(r); 
}}><X className="size-3.5" /> Decline</Button>
                                        </div>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}

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

            {/* Approve */}
            <Dialog open={!!approve} onOpenChange={(v) => !v && setApprove(null)}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Approve refund</DialogTitle>
                        <DialogDescription>Refund {approve?.requester} for “{approve?.event}”. You can refund the full amount or a partial sum (up to {approve ? rm(approve.remaining) : ''}).</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submitApprove} className="grid gap-3">
                        <div className="grid gap-1.5">
                            <Label htmlFor="amount">Refund amount (RM)</Label>
                            <input id="amount" type="number" step="0.01" min="0.01" max={approve?.remaining} className={input}
                                value={approveForm.data.amount} onChange={(e) => approveForm.setData('amount', e.target.value)} />
                            {approveForm.errors.amount && <p className="text-xs text-destructive">{approveForm.errors.amount}</p>}
                        </div>
                        <DialogFooter className="mt-1 gap-2">
                            <Button type="button" variant="ghost" onClick={() => setApprove(null)}>Cancel</Button>
                            <Button type="submit" disabled={approveForm.processing}>{approveForm.processing ? 'Refunding…' : 'Approve & refund'}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Decline */}
            <Dialog open={!!decline} onOpenChange={(v) => !v && setDecline(null)}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Decline refund</DialogTitle>
                        <DialogDescription>Let {decline?.requester} know why (optional). They’ll be notified.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submitDecline} className="grid gap-3">
                        <div className="grid gap-1.5">
                            <Label htmlFor="note">Note</Label>
                            <textarea id="note" rows={3} value={declineForm.data.note} onChange={(e) => declineForm.setData('note', e.target.value)}
                                className="w-full rounded-lg border border-input bg-card px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20"
                                placeholder="e.g. outside the refund window" />
                            {declineForm.errors.note && <p className="text-xs text-destructive">{declineForm.errors.note}</p>}
                        </div>
                        <DialogFooter className="mt-1 gap-2">
                            <Button type="button" variant="ghost" onClick={() => setDecline(null)}>Cancel</Button>
                            <Button type="submit" variant="destructive" disabled={declineForm.processing}>{declineForm.processing ? 'Declining…' : 'Decline'}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

HostRefunds.layout = { breadcrumbs: [{ title: 'Refunds', href: '/host/refunds' }] };
