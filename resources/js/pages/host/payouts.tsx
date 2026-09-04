import { Head, router, useForm, usePage } from '@inertiajs/react';
import { CheckCircle2, Download, FileText, Landmark, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import { AppSelect } from '@/components/ui/app-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';

interface Balance { gross: number; fee_label: string; net: number; withdrawn: number; available: number; pending_clearance: number }
interface PayoutRow { reference: string; amount: number; currency: string; status: string; requested_at: string | null; paid_at: string | null }
interface Bank { bank_code: string | null; account_number: string | null; account_name: string | null }
interface Business { business_name: string | null; tax_number: string | null; business_address: string | null }
interface BankOption { value: string; label: string }

const field = 'h-10 w-full rounded-lg border border-input bg-card px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

function Line({ label, value, strong }: { label: string; value: string; strong?: boolean }) {
    return (
        <div className={`flex justify-between py-1.5 ${strong ? 'text-base font-semibold' : 'text-sm text-muted-foreground'}`}>
            <span>{label}</span><span className={strong ? '' : 'text-foreground'}>{value}</span>
        </div>
    );
}

export default function Payouts({ balance, payouts, bank, business, banks }: { balance: Balance; payouts: PayoutRow[]; bank: Bank; business: Business; banks: BankOption[] }) {
    const flash = usePage().props.flash as { success?: string; error?: string } | undefined;
    const errors = usePage().props.errors as Record<string, string>;
    const rm = (n: number) => `RM ${n.toFixed(2)}`;

    const [bankOpen, setBankOpen] = useState(false);
    const [bizOpen, setBizOpen] = useState(false);
    const hasBiz = !!(business.business_name || business.tax_number || business.business_address);
    const bizForm = useForm({
        business_name: business.business_name ?? '',
        tax_number: business.tax_number ?? '',
        business_address: business.business_address ?? '',
    });
    const openBiz = () => {
        bizForm.setData({ business_name: business.business_name ?? '', tax_number: business.tax_number ?? '', business_address: business.business_address ?? '' });
        bizForm.clearErrors();
        setBizOpen(true);
    };
    const saveBiz = (e: React.FormEvent) => {
        e.preventDefault();
        bizForm.post('/host/payouts/business', { preserveScroll: true, onSuccess: () => setBizOpen(false) });
    };
    const hasBank = !!(bank.bank_code && bank.account_number && bank.account_name);
    const bankName = banks.find((b) => b.value === bank.bank_code)?.label ?? bank.bank_code ?? '';
    const maskedAccount = bank.account_number ? `•••• ${bank.account_number.slice(-4)}` : '';

    const bankForm = useForm({
        bank_code: bank.bank_code ?? '',
        account_number: bank.account_number ?? '',
        account_name: bank.account_name ?? '',
    });
    const openBank = () => {
        // Reset the form to the saved values (or blanks) each time it opens.
        bankForm.setData({ bank_code: bank.bank_code ?? '', account_number: bank.account_number ?? '', account_name: bank.account_name ?? '' });
        bankForm.clearErrors();
        setBankOpen(true);
    };
    const saveBank = (e: React.FormEvent) => {
        e.preventDefault();
        bankForm.post('/host/payouts/bank', { preserveScroll: true, onSuccess: () => setBankOpen(false) });
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
                        <Line label="Ticket revenue (yours to keep)" value={rm(balance.net)} strong />
                        {balance.pending_clearance > 0 && <Line label="Held until events end" value={`− ${rm(balance.pending_clearance)}`} />}
                        <Line label="Already paid out / requested" value={`− ${rm(balance.withdrawn)}`} />
                    </div>
                    <p className="mt-2 text-xs text-muted-foreground">You keep the full ticket price — the platform fee ({balance.fee_label}) is paid by buyers at checkout, not deducted from you.</p>

                    {balance.pending_clearance > 0 && (
                        <p className="mt-3 rounded-lg bg-muted/50 px-3 py-2 text-xs text-muted-foreground">{rm(balance.pending_clearance)} becomes available for payout once those events have taken place.</p>
                    )}

                    <Button className="mt-5 w-full" size="lg" disabled={balance.available <= 0} onClick={() => router.post('/host/payouts', {}, { preserveScroll: true })}>
                        Request payout of {rm(balance.available)}
                    </Button>
                </div>

                {/* Payout bank account — empty state → modal → saved card */}
                <div className="mt-6 rounded-2xl border border-border bg-card p-5 shadow-sm">
                    <div className="flex items-start justify-between gap-3">
                        <div>
                            <h2 className="text-sm font-semibold">Payout bank account</h2>
                            <p className="mt-0.5 text-xs text-muted-foreground">Where we send your money. Required for automated (instant) payouts.</p>
                        </div>
                        {hasBank && <Button type="button" variant="outline" size="sm" onClick={openBank}><Pencil className="size-3.5" /> Edit</Button>}
                    </div>

                    <div className="mt-4">
                        {hasBank ? (
                            <div className="flex items-start gap-3 rounded-xl border border-border bg-muted/30 p-4">
                                <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-foreground/5 text-foreground"><Landmark className="size-5" /></div>
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="truncate font-semibold">{bankName}</span>
                                        <span className="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2 py-0.5 text-[11px] font-medium text-emerald-600 dark:text-emerald-400"><CheckCircle2 className="size-3" /> Ready</span>
                                    </div>
                                    <div className="mt-1 font-mono text-sm tabular-nums text-muted-foreground">{maskedAccount}</div>
                                    <div className="mt-0.5 truncate text-sm text-muted-foreground">{bank.account_name}</div>
                                </div>
                            </div>
                        ) : (
                            <div className="flex flex-col items-center justify-center rounded-xl border border-dashed border-border px-4 py-8 text-center">
                                <div className="flex size-11 items-center justify-center rounded-full bg-muted text-muted-foreground"><Landmark className="size-5" /></div>
                                <p className="mt-3 text-sm font-medium">No bank details set up</p>
                                <p className="mt-1 max-w-xs text-xs text-muted-foreground">Add your bank account so we can send your payouts. Required for automated (instant) transfers.</p>
                                <Button type="button" className="mt-4" onClick={openBank}><Plus className="size-4" /> Set up bank</Button>
                            </div>
                        )}
                    </div>
                </div>

                {/* Bank details modal */}
                <Dialog open={bankOpen} onOpenChange={setBankOpen}>
                    <DialogContent className="sm:max-w-md">
                        <DialogHeader>
                            <DialogTitle>{hasBank ? 'Edit bank details' : 'Set up your bank'}</DialogTitle>
                            <DialogDescription>Where we send your payouts. Make sure the account holder name matches your bank exactly.</DialogDescription>
                        </DialogHeader>
                        <form onSubmit={saveBank} className="grid gap-3">
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
                            <DialogFooter className="mt-2 gap-2">
                                <Button type="button" variant="ghost" onClick={() => setBankOpen(false)}>Cancel</Button>
                                <Button type="submit" disabled={bankForm.processing}>{bankForm.processing ? 'Saving…' : 'Save bank details'}</Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>

                {/* Invoice & tax details — printed on the receipts you issue to attendees */}
                <div className="mt-6 rounded-2xl border border-border bg-card p-5 shadow-sm">
                    <div className="flex items-start justify-between gap-3">
                        <div>
                            <h2 className="text-sm font-semibold">Invoice &amp; tax details</h2>
                            <p className="mt-0.5 text-xs text-muted-foreground">Your business name, address and SST/tax number — shown on the receipts your attendees download.</p>
                        </div>
                        {hasBiz && <Button type="button" variant="outline" size="sm" onClick={openBiz}><Pencil className="size-3.5" /> Edit</Button>}
                    </div>

                    <div className="mt-4">
                        {hasBiz ? (
                            <div className="flex items-start gap-3 rounded-xl border border-border bg-muted/30 p-4">
                                <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-foreground/5 text-foreground"><FileText className="size-5" /></div>
                                <div className="min-w-0 flex-1 text-sm">
                                    {business.business_name && <div className="font-semibold">{business.business_name}</div>}
                                    {business.business_address && <div className="whitespace-pre-line text-muted-foreground">{business.business_address}</div>}
                                    {business.tax_number && <div className="text-muted-foreground">SST/Tax No: {business.tax_number}</div>}
                                </div>
                            </div>
                        ) : (
                            <div className="flex flex-col items-center justify-center rounded-xl border border-dashed border-border px-4 py-8 text-center">
                                <div className="flex size-11 items-center justify-center rounded-full bg-muted text-muted-foreground"><FileText className="size-5" /></div>
                                <p className="mt-3 text-sm font-medium">No invoice details yet</p>
                                <p className="mt-1 max-w-xs text-xs text-muted-foreground">Add your business name and SST/tax number so they appear on the receipts you issue.</p>
                                <Button type="button" className="mt-4" onClick={openBiz}><Plus className="size-4" /> Add details</Button>
                            </div>
                        )}
                    </div>
                </div>

                {/* Invoice details modal */}
                <Dialog open={bizOpen} onOpenChange={setBizOpen}>
                    <DialogContent className="sm:max-w-md">
                        <DialogHeader>
                            <DialogTitle>{hasBiz ? 'Edit invoice details' : 'Add invoice details'}</DialogTitle>
                            <DialogDescription>These appear on the receipts your attendees download. Leave the tax number blank if you’re not SST-registered.</DialogDescription>
                        </DialogHeader>
                        <form onSubmit={saveBiz} className="grid gap-3">
                            <div className="grid gap-1.5">
                                <Label htmlFor="business_name">Business name</Label>
                                <input id="business_name" className={field} value={bizForm.data.business_name} onChange={(e) => bizForm.setData('business_name', e.target.value)} placeholder="e.g. Acme Events Sdn Bhd" />
                                {bizForm.errors.business_name && <p className="text-xs text-destructive">{bizForm.errors.business_name}</p>}
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="tax_number">SST / tax number</Label>
                                <input id="tax_number" className={field} value={bizForm.data.tax_number} onChange={(e) => bizForm.setData('tax_number', e.target.value)} placeholder="e.g. W10-1808-31000123" />
                                {bizForm.errors.tax_number && <p className="text-xs text-destructive">{bizForm.errors.tax_number}</p>}
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="business_address">Business address</Label>
                                <textarea id="business_address" rows={3} className="w-full rounded-lg border border-input bg-card px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20" value={bizForm.data.business_address} onChange={(e) => bizForm.setData('business_address', e.target.value)} placeholder="Street, city, postcode" />
                                {bizForm.errors.business_address && <p className="text-xs text-destructive">{bizForm.errors.business_address}</p>}
                            </div>
                            <DialogFooter className="mt-2 gap-2">
                                <Button type="button" variant="ghost" onClick={() => setBizOpen(false)}>Cancel</Button>
                                <Button type="submit" disabled={bizForm.processing}>{bizForm.processing ? 'Saving…' : 'Save invoice details'}</Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>

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
