import { Head, router, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Banknote } from 'lucide-react';

interface PayoutRow { reference: string; organizer: string | null; email: string | null; amount: number; currency: string; status: string; requested_at: string | null; paid_at: string | null }

export default function AdminPayouts({ payouts }: { payouts: PayoutRow[] }) {
    const flash = usePage().props.flash as { success?: string } | undefined;

    const markPaid = (p: PayoutRow) => {
        if (confirm(`Mark ${p.reference} (RM ${p.amount.toFixed(2)}) to ${p.organizer} as paid?`)) {
            router.post(`/admin/payouts/${p.reference}/paid`, {}, { preserveScroll: true });
        }
    };

    return (
        <>
            <Head title="Payout requests" />
            <div className="mx-auto w-full max-w-4xl flex-1 p-4">
                <h1 className="mb-6 text-2xl font-bold tracking-tight">Payout requests</h1>
                {flash?.success && <div className="mb-4 rounded-lg border border-foreground bg-foreground p-3 text-sm text-background">{flash.success}</div>}

                {payouts.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 rounded-xl border border-dashed border-border py-16 text-center">
                        <Banknote className="size-8 text-muted-foreground" />
                        <p className="text-sm text-muted-foreground">No payout requests.</p>
                    </div>
                ) : (
                    <div className="overflow-hidden rounded-xl border border-border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left text-xs uppercase tracking-wide text-muted-foreground">
                                <tr><th className="px-4 py-3 font-medium">Organizer</th><th className="px-4 py-3 font-medium">Amount</th><th className="px-4 py-3 font-medium">Requested</th><th className="px-4 py-3 font-medium">Status</th><th className="px-4 py-3 text-right font-medium">Actions</th></tr>
                            </thead>
                            <tbody className="divide-y divide-border">
                                {payouts.map((p) => (
                                    <tr key={p.reference} className="hover:bg-muted/30">
                                        <td className="px-4 py-3">
                                            <div className="font-medium">{p.organizer ?? '—'}</div>
                                            <div className="text-xs text-muted-foreground">{p.email} · {p.reference}</div>
                                        </td>
                                        <td className="px-4 py-3 font-medium whitespace-nowrap">{p.currency} {p.amount.toFixed(2)}</td>
                                        <td className="px-4 py-3 text-muted-foreground">{p.requested_at}</td>
                                        <td className="px-4 py-3"><Badge variant={p.status === 'paid' ? 'default' : 'secondary'} className="capitalize">{p.status}</Badge></td>
                                        <td className="px-4 py-3 text-right">
                                            {p.status === 'pending'
                                                ? <Button size="sm" onClick={() => markPaid(p)}>Mark paid</Button>
                                                : <span className="text-xs text-muted-foreground">{p.paid_at}</span>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </>
    );
}

AdminPayouts.layout = {
    breadcrumbs: [{ title: 'Payout requests', href: '/admin/payouts' }],
};
