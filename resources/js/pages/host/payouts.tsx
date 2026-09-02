import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { AppSelect } from '@/components/ui/app-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

interface Balance { gross: number; fee_percent: number; fee_type: string; fee_label: string; fee: number; net: number; withdrawn: number; available: number; pending_clearance: number }
interface PayoutRow { reference: string; amount: number; currency: string; status: string; requested_at: string | null; paid_at: string | null }
interface Bank { bank_code: string | null; account_number: string | null; account_name: string | null }
interface BankOption { value: string; label: string }

const field = 'h-10 w-full rounded-lg border border-input bg-card px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

function Line({ label, value, strong }: { label: string; value: string; strong?: boolean }) {
    return (
        <div className={`flex justify-between py-1.5 ${strong ? 'text-base font-semibold' : 'text-sm text-muted-foreground'}`}>
            <span>{label}</span><span className={strong ? '' : 'text-foreground'}>{value}</span>
        </div>
    );
}

export default function Payouts({ balance, payouts, bank, banks }: { balance: Balance; payouts: PayoutRow[]; bank: Bank; banks: BankOption[] }) {
    const flash = usePage().props.flash as { success?: string; error?: string } | undefined;
    const errors = usePage().props.errors as Record<string, string>;
    const rm = (n: number) => `RM ${n.toFixed(2)}`;

    const bankForm = useForm({
        bank_code: bank.bank_code ?? '',
        account_number: bank.account_number ?? '',
        account_name: bank.account_name ?? '',
    });
    const saveBank = (e: React.FormEvent) => {
        e.preventDefault();
        bankForm.post('/host/payouts/bank', { preserveScroll: true });
    };

    return (
        <>
            <Head title="Payouts" />
            <div className="mx-auto w-full max-w-2xl flex-1 p-4">
                <h1 className="mb-6 text-2xl font-bold tracking-tight">Payouts</h1>

                {flash?.success && <div className="mb-4 rounded-lg border border-foreground bg-foreground p-3 text-sm text-background">{flash.success}</div>}
                {errors?.payout && <div className="mb-4 rounded-lg border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive">{errors.payout}</div>}

                <div className="rounded-2xl border border-border bg-card p-6 shadow-sm">
                    <div className="text-xs uppercase tracking-widest text-muted-foreground">Available to withdraw</div>
                    <div className="mt-1 text-4xl font-bold tabular-nums">{rm(balance.available)}</div>

                    <div className="mt-5 border-t border-border pt-3">
                        <Line label="Gross ticket revenue" value={rm(balance.gross)} />
                        <Line label={`Platform fee (${balance.fee_label})`} value={`− ${rm(balance.fee)}`} />
                        <Line label="Net earnings" value={rm(balance.net)} strong />
                        {balance.pending_clearance > 0 && <Line label="Held until events end" value={`− ${rm(balance.pending_clearance)}`} />}
                        <Line label="Already paid out / requested" value={`− ${rm(balance.withdrawn)}`} />
                    </div>

                    {balance.pending_clearance > 0 && (
                        <p className="mt-3 rounded-lg bg-muted/50 px-3 py-2 text-xs text-muted-foreground">{rm(balance.pending_clearance)} becomes available for payout once those events have taken place.</p>
                    )}

                    <Button className="mt-5 w-full" size="lg" disabled={balance.available <= 0} onClick={() => router.post('/host/payouts', {}, { preserveScroll: true })}>
                        Request payout of {rm(balance.available)}
                    </Button>
                </div>

                {/* Bank account for automated payouts */}
                <form onSubmit={saveBank} className="mt-6 rounded-2xl border border-border bg-card p-5 shadow-sm">
                    <h2 className="text-sm font-semibold">Payout bank account</h2>
                    <p className="mt-0.5 text-xs text-muted-foreground">Where we send your money. Required for automated (instant) payouts.</p>
                    <div className="mt-4 grid gap-3">
                        <div className="grid gap-1.5">
                            <Label>Bank</Label>
                            <AppSelect value={bankForm.data.bank_code} onChange={(v) => bankForm.setData('bank_code', v)} options={[{ value: '', label: 'Select your bank…' }, ...banks]} />
                            {bankForm.errors.bank_code && <p className="text-xs text-destructive">{bankForm.errors.bank_code}</p>}
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="account_number">Account number</Label>
                            <input id="account_number" inputMode="numeric" className={field} value={bankForm.data.account_number} onChange={(e) => bankForm.setData('account_number', e.target.value.replace(/[^0-9]/g, ''))} placeholder="e.g. 1234567890" />
                            {bankForm.errors.account_number && <p className="text-xs text-destructive">{bankForm.errors.account_number}</p>}
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="account_name">Account holder name</Label>
                            <input id="account_name" className={field} value={bankForm.data.account_name} onChange={(e) => bankForm.setData('account_name', e.target.value)} placeholder="As it appears on your bank account" />
                            {bankForm.errors.account_name && <p className="text-xs text-destructive">{bankForm.errors.account_name}</p>}
                        </div>
                        <Button type="submit" variant="outline" className="w-max" disabled={bankForm.processing}>Save bank details</Button>
                    </div>
                </form>

                <div className="mt-6 rounded-2xl border border-border bg-card p-5 shadow-sm">
                    <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-muted-foreground">History</h2>
                    {payouts.length === 0 ? (
                        <p className="text-sm text-muted-foreground">No payout requests yet.</p>
                    ) : (
                        <ul className="grid gap-1.5 text-sm">
                            {payouts.map((p) => (
                                <li key={p.reference} className="flex items-center justify-between border-b border-border/60 py-2 last:border-0">
                                    <div>
                                        <div className="font-medium">{rm(p.amount)}</div>
                                        <div className="text-xs text-muted-foreground">{p.reference} · requested {p.requested_at}{p.paid_at ? ` · paid ${p.paid_at}` : ''}</div>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        {p.status === 'paid' && (
                                            <a href={`/my/payouts/${p.reference}/receipt`} target="_blank" rel="noopener" className="inline-flex items-center gap-1 text-xs font-medium text-muted-foreground hover:text-foreground"><Download className="size-3.5" /> Receipt</a>
                                        )}
                                        <Badge variant={p.status === 'paid' ? 'default' : 'secondary'} className="capitalize">{p.status}</Badge>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </>
    );
}

Payouts.layout = {
    breadcrumbs: [{ title: 'Payouts', href: '/host/payouts' }],
};
