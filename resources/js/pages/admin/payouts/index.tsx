import { Head, router, usePage } from '@inertiajs/react';
import { Banknote, CheckCircle2, HandCoins, RefreshCw, Send, XCircle } from 'lucide-react';
import { useConfirm } from '@/components/confirm-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

interface Bank { name: string; account: string; holder: string }
interface PayoutRow {
    reference: string; organizer: string | null; email: string | null; amount: number; currency: string;
    status: string; method: string | null; chip_state: string | null; bank: Bank | null;
    requested_at: string | null; paid_at: string | null;
}

const TONE: Record<string, 'default' | 'secondary' | 'outline'> = { paid: 'default', processing: 'outline', pending: 'secondary' };

export default function AdminPayouts({ payouts, sendEnabled }: { payouts: PayoutRow[]; sendEnabled: boolean }) {
    const flash = usePage().props.flash as { success?: string } | undefined;
    const errors = usePage().props.errors as Record<string, string> | undefined;
    const confirm = useConfirm();
    const rm = (p: PayoutRow) => `${p.currency} ${p.amount.toFixed(2)}`;

    const markPaid = async (p: PayoutRow) => {
        if (await confirm({ title: 'Mark payout as paid?', description: `${p.reference} · ${rm(p)} to ${p.organizer}. Use this when you've paid them outside the system.`, confirmText: 'Mark paid' })) {
            router.post(`/admin/payouts/${p.reference}/paid`, {}, { preserveScroll: true });
        }
    };
    const sendChip = async (p: PayoutRow) => {
        if (await confirm({ title: 'Send this payout via CHIP?', description: `${rm(p)} to ${p.bank?.holder} · ${p.bank?.name} ${p.bank?.account}. This transfers real money.`, confirmText: 'Send now', destructive: true })) {
            router.post(`/admin/payouts/${p.reference}/send`, {}, { preserveScroll: true });
        }
    };
    const sync = (p: PayoutRow) => router.post(`/admin/payouts/${p.reference}/sync`, {}, { preserveScroll: true });

    return (
        <>
            <Head title="Payout requests" />
            <div className="mx-auto w-full max-w-4xl flex-1 p-4">
                <h1 className="mb-1 text-2xl font-bold tracking-tight">Payout requests</h1>
                <p className="mb-4 text-sm text-muted-foreground">Pay organizers automatically via CHIP Send, or mark a manual bank transfer as paid.</p>
                {flash?.success && <div className="mb-4 rounded-lg border border-foreground bg-foreground p-3 text-sm text-background">{flash.success}</div>}
                {errors?.payout && <div className="mb-4 rounded-lg border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive">{errors.payout}</div>}

                {/* Payout methods status */}
                <div className="mb-6 grid gap-3 sm:grid-cols-2">
                    <div className="rounded-2xl border border-border bg-card p-4 shadow-sm">
                        <div className="flex items-center gap-2 font-medium"><HandCoins className="size-4" /> Manual bank transfer</div>
                        <div className="mt-1 flex items-center gap-1.5 text-xs text-[#2ec4b6]"><CheckCircle2 className="size-3.5" /> Always available</div>
                        <p className="mt-2 text-xs text-muted-foreground">Pay the organizer from your own bank, then click “Mark paid manually” on their request.</p>
                    </div>
                    <div className="rounded-2xl border border-border bg-card p-4 shadow-sm">
                        <div className="flex items-center gap-2 font-medium"><Send className="size-4" /> Automated (CHIP Send)</div>
                        {sendEnabled ? (
                            <div className="mt-1 flex items-center gap-1.5 text-xs text-[#2ec4b6]"><CheckCircle2 className="size-3.5" /> Active</div>
                        ) : (
                            <>
                                <div className="mt-1 flex items-center gap-1.5 text-xs text-amber-600"><XCircle className="size-3.5" /> Not activated</div>
                                <p className="mt-2 text-xs text-muted-foreground">CHIP Send is a separate product — request it from your CHIP account manager, then add <code>CHIP_SEND_API_KEY</code> / <code>CHIP_SEND_API_SECRET</code>. Until then, use manual payouts.</p>
                            </>
                        )}
                    </div>
                </div>

                {payouts.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 rounded-xl border border-dashed border-border py-16 text-center">
                        <Banknote className="size-8 text-muted-foreground" />
                        <p className="text-sm text-muted-foreground">No payout requests.</p>
                    </div>
                ) : (
                    <div className="grid gap-3">
                        {payouts.map((p) => (
                            <div key={p.reference} className="rounded-2xl border border-border bg-card p-4 shadow-sm">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-semibold">{p.organizer ?? '—'}</span>
                                            <Badge variant={TONE[p.status] ?? 'secondary'} className="capitalize">{p.status === 'processing' && p.chip_state ? `processing · ${p.chip_state}` : p.status}</Badge>
                                        </div>
                                        <div className="mt-0.5 text-xs text-muted-foreground">{p.email} · {p.reference} · requested {p.requested_at}</div>
                                        <div className="mt-1 text-xs text-muted-foreground">
                                            {p.bank ? <>Bank: {p.bank.name} · {p.bank.account} · {p.bank.holder}</> : <span className="text-amber-600">No bank details — organizer must add them for automated payout.</span>}
                                        </div>
                                    </div>
                                    <div className="text-right">
                                        <div className="text-lg font-bold tabular-nums">{rm(p)}</div>
                                        {p.status === 'paid' && <div className="text-xs text-muted-foreground">{p.method} · {p.paid_at}</div>}
                                    </div>
                                </div>

                                {(p.status === 'pending' || p.status === 'processing') && (
                                    <div className="mt-3 flex flex-wrap justify-end gap-2 border-t border-border pt-3">
                                        {p.status === 'processing' && <Button size="sm" variant="ghost" onClick={() => sync(p)}><RefreshCw className="size-3.5" /> Refresh status</Button>}
                                        {p.status === 'pending' && (
                                            <>
                                                <Button size="sm" variant="outline" onClick={() => markPaid(p)}>Mark paid manually</Button>
                                                {sendEnabled && <Button size="sm" disabled={!p.bank} onClick={() => sendChip(p)}><Send className="size-3.5" /> Send via CHIP</Button>}
                                            </>
                                        )}
                                    </div>
                                )}
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

AdminPayouts.layout = {
    breadcrumbs: [{ title: 'Payout requests', href: '/admin/payouts' }],
};
