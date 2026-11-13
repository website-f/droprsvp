import { Head, router, usePage } from '@inertiajs/react';
import { Banknote, CheckCircle2, FileImage, HandCoins, ImageUp, Loader2, Plus, RefreshCw, Search, Send, X, XCircle } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useConfirm } from '@/components/confirm-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { uploadImageWithToast } from '@/lib/upload';
import { cn } from '@/lib/utils';

interface Bank { name: string; account: string; holder: string }
interface PayoutRow {
    reference: string; organizer: string | null; email: string | null; amount: number; currency: string;
    status: string; method: string | null; chip_state: string | null; bank: Bank | null;
    requested_at: string | null; paid_at: string | null;
    note: string | null; proof: string | null; created_by: string | null;
}
interface OrganizerOption { id: number; name: string; email: string; available: number; clearing: number; outstanding: number; bank: Bank | null }

const TONE: Record<string, 'default' | 'secondary' | 'outline'> = { paid: 'default', processing: 'outline', pending: 'secondary' };
const METHODS = ['Bank transfer', 'DuitNow', 'Cash', 'Cheque', 'Other'];
const field = 'h-10 w-full rounded-lg border border-input bg-card px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';
const money = (n: number) => `RM ${n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

/** How it was paid, the bank reference, and the slip — shared by both dialogs. */
function ProofFields({ method, setMethod, note, setNote, proof, setProof, errors }: {
    method: string; setMethod: (v: string) => void; note: string; setNote: (v: string) => void;
    proof: string; setProof: (v: string) => void; errors: Record<string, string | undefined>;
}) {
    const fileRef = useRef<HTMLInputElement>(null);
    const [busy, setBusy] = useState(false);

    const upload = async (file?: File) => {
        if (!file) {
            return;
        }

        setBusy(true);
        const url = await uploadImageWithToast(file);
        setBusy(false);

        if (url) {
            setProof(url);
        }
    };

    return (
        <div className="grid gap-4">
            <div className="grid gap-1.5">
                <Label>Paid by</Label>
                <div className="flex flex-wrap gap-1.5">
                    {METHODS.map((m) => (
                        <button key={m} type="button" onClick={() => setMethod(m)}
                            className={cn('rounded-full border px-3 py-1.5 text-xs font-medium transition-colors', method === m ? 'border-foreground bg-foreground text-background' : 'border-border hover:border-foreground/40')}>
                            {m}
                        </button>
                    ))}
                </div>
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor="payout-note">Transfer reference or note <span className="font-normal text-muted-foreground">— optional</span></Label>
                <input id="payout-note" className={field} value={note} maxLength={255} onChange={(e) => setNote(e.target.value)} placeholder="e.g. Maybank ref 20261113-88231" />
                {errors.note && <p className="text-xs text-destructive">{errors.note}</p>}
            </div>
            <div className="grid gap-1.5">
                <Label>Proof of payment <span className="font-normal text-muted-foreground">— transfer slip or screenshot</span></Label>
                <input ref={fileRef} type="file" accept="image/png,image/jpeg,image/webp" className="hidden" onChange={(e) => upload(e.target.files?.[0])} />
                {proof ? (
                    <div className="flex items-center gap-3 rounded-lg border border-border p-2">
                        <a href={proof} target="_blank" rel="noopener noreferrer" className="shrink-0"><img src={proof} alt="Proof of payment" className="size-14 rounded-md object-cover" /></a>
                        <span className="min-w-0 flex-1 text-xs text-muted-foreground">Attached. The organizer can open it from their payouts and from the email.</span>
                        <button type="button" onClick={() => setProof('')} aria-label="Remove proof" className="flex size-8 shrink-0 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"><X className="size-4" /></button>
                    </div>
                ) : (
                    <Button type="button" variant="outline" className="w-full justify-center" disabled={busy} onClick={() => fileRef.current?.click()}>
                        {busy ? <Loader2 className="size-4 animate-spin" /> : <ImageUp className="size-4" />} Upload transfer slip
                    </Button>
                )}
                <p className="text-xs text-muted-foreground">JPG, PNG or WEBP. Take a screenshot of a PDF slip.</p>
            </div>
        </div>
    );
}

/** "Record a payout" — for an organizer who never pressed "Request payout". */
function RecordPayout({ open, onClose, prefill }: { open: boolean; onClose: () => void; prefill: OrganizerOption | null }) {
    const [q, setQ] = useState('');
    const [options, setOptions] = useState<OrganizerOption[]>([]);
    const [picked, setPicked] = useState<OrganizerOption | null>(prefill);
    const [amount, setAmount] = useState(prefill ? String(prefill.available || prefill.outstanding) : '');
    const [paid, setPaid] = useState(true);
    const [method, setMethod] = useState('Bank transfer');
    const [note, setNote] = useState('');
    const [proof, setProof] = useState('');
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string | undefined>>({});

    useEffect(() => {
        if (!open || picked) {
            return;
        }

        const t = window.setTimeout(() => {
            fetch(`/admin/payouts/organizers?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } })
                .then((r) => (r.ok ? r.json() : []))
                .then(setOptions)
                .catch(() => setOptions([]));
        }, 250);

        return () => window.clearTimeout(t);
    }, [q, open, picked]);

    const pick = (o: OrganizerOption) => {
        setPicked(o);
        setAmount(String(o.available > 0 ? o.available : o.outstanding));
    };

    const submit = () => {
        setBusy(true);
        router.post('/admin/payouts', { user_id: picked?.id ?? null, amount, paid, method, note, proof }, {
            preserveScroll: true,
            onError: (e) => setErrors(e),
            onSuccess: () => onClose(),
            onFinish: () => setBusy(false),
        });
    };

    const value = parseFloat(amount || '0');

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Record a payout</DialogTitle>
                    <DialogDescription>Pay an organizer without waiting for them to request it. It appears in their payouts like any other.</DialogDescription>
                </DialogHeader>

                <div className="grid gap-4">
                    <div className="grid gap-1.5">
                        <Label>Organizer</Label>
                        {picked ? (
                            <div className="rounded-lg border border-border p-3">
                                <div className="flex items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <div className="font-medium [overflow-wrap:anywhere]">{picked.name}</div>
                                        <div className="text-xs text-muted-foreground [overflow-wrap:anywhere]">{picked.email}</div>
                                    </div>
                                    {!prefill && <Button type="button" variant="ghost" size="sm" onClick={() => setPicked(null)}>Change</Button>}
                                </div>
                                <div className="mt-2 grid grid-cols-3 gap-2 text-xs">
                                    <div><div className="text-muted-foreground">Ready</div><div className="font-semibold tabular-nums">{money(picked.available)}</div></div>
                                    <div><div className="text-muted-foreground">Clearing</div><div className="font-semibold tabular-nums">{money(picked.clearing)}</div></div>
                                    <div><div className="text-muted-foreground">Owed in all</div><div className="font-semibold tabular-nums">{money(picked.outstanding)}</div></div>
                                </div>
                                <div className="mt-2 text-xs text-muted-foreground">
                                    {picked.bank ? <>Pay to: {picked.bank.name} · {picked.bank.account} · {picked.bank.holder}</> : <span className="text-amber-600 dark:text-amber-400">No bank details on file — ask the organizer for them.</span>}
                                </div>
                            </div>
                        ) : (
                            <>
                                <div className="relative">
                                    <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                                    <input autoFocus className={cn(field, 'pl-9')} value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search by business, name or email" />
                                </div>
                                <ul className="max-h-60 overflow-y-auto rounded-lg border border-border">
                                    {options.length === 0 && <li className="px-3 py-3 text-sm text-muted-foreground">No organizers found.</li>}
                                    {options.map((o) => (
                                        <li key={o.id}>
                                            <button type="button" onClick={() => pick(o)} className="flex w-full items-center justify-between gap-3 border-b border-border/60 px-3 py-2.5 text-left last:border-0 hover:bg-accent">
                                                <span className="min-w-0">
                                                    <span className="block text-sm font-medium [overflow-wrap:anywhere]">{o.name}</span>
                                                    <span className="block text-xs text-muted-foreground [overflow-wrap:anywhere]">{o.email}</span>
                                                </span>
                                                <span className="shrink-0 text-right text-xs">
                                                    <span className="block font-semibold tabular-nums">{money(o.outstanding)}</span>
                                                    <span className="text-muted-foreground">owed</span>
                                                </span>
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            </>
                        )}
                        {errors.user_id && <p className="text-xs text-destructive">{errors.user_id}</p>}
                    </div>

                    {picked && (
                        <>
                            <div className="grid gap-1.5">
                                <Label htmlFor="payout-amount">Amount (RM)</Label>
                                <input id="payout-amount" type="number" min="0.01" step="0.01" inputMode="decimal" className={field} value={amount} onChange={(e) => setAmount(e.target.value)} />
                                <div className="flex flex-wrap gap-1.5">
                                    {picked.available > 0 && <button type="button" onClick={() => setAmount(String(picked.available))} className="rounded-full border border-border px-2.5 py-1 text-xs hover:border-foreground/40">Ready: {money(picked.available)}</button>}
                                    {picked.outstanding > picked.available && <button type="button" onClick={() => setAmount(String(picked.outstanding))} className="rounded-full border border-border px-2.5 py-1 text-xs hover:border-foreground/40">All owed: {money(picked.outstanding)}</button>}
                                </div>
                                {value > picked.available && value <= picked.outstanding && (
                                    <p className="text-xs text-amber-600 dark:text-amber-400">This includes money from events that have not happened yet — a refund after paying would leave you short.</p>
                                )}
                                {errors.amount && <p className="text-xs text-destructive">{errors.amount}</p>}
                            </div>

                            <div className="flex items-center justify-between gap-4 rounded-lg border border-border p-3">
                                <div>
                                    <div className="text-sm font-medium">I’ve already paid this</div>
                                    <div className="text-xs text-muted-foreground">{paid ? 'Recorded as paid now, and the organizer is emailed.' : 'Created as pending — pay it later from this page.'}</div>
                                </div>
                                <Switch checked={paid} onCheckedChange={setPaid} aria-label="Already paid" />
                            </div>

                            {paid && <ProofFields method={method} setMethod={setMethod} note={note} setNote={setNote} proof={proof} setProof={setProof} errors={errors} />}
                        </>
                    )}
                </div>

                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
                    <Button type="button" disabled={!picked || !(value > 0) || busy} onClick={submit}>
                        {busy && <Loader2 className="size-4 animate-spin" />}
                        {paid ? `Record ${value > 0 ? money(value) : ''} as paid` : 'Create payout'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** "Mark paid" for an existing request — with method, reference and the slip. */
function MarkPaid({ payout, onClose }: { payout: PayoutRow | null; onClose: () => void }) {
    const [method, setMethod] = useState('Bank transfer');
    const [note, setNote] = useState('');
    const [proof, setProof] = useState('');
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string | undefined>>({});

    const submit = () => {
        if (!payout) {
            return;
        }

        setBusy(true);
        router.post(`/admin/payouts/${payout.reference}/paid`, { method, note, proof }, {
            preserveScroll: true,
            onError: (e) => setErrors(e),
            onSuccess: () => onClose(),
            onFinish: () => setBusy(false),
        });
    };

    return (
        <Dialog open={!!payout} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Mark as paid</DialogTitle>
                    <DialogDescription>
                        {payout && <>{payout.reference} · {payout.currency} {payout.amount.toFixed(2)} to {payout.organizer}. Use this once you’ve paid them outside the system.</>}
                    </DialogDescription>
                </DialogHeader>
                {payout?.bank && (
                    <div className="rounded-lg bg-muted/50 px-3 py-2 text-xs text-muted-foreground">Their bank: {payout.bank.name} · {payout.bank.account} · {payout.bank.holder}</div>
                )}
                <ProofFields method={method} setMethod={setMethod} note={note} setNote={setNote} proof={proof} setProof={setProof} errors={errors} />
                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
                    <Button type="button" disabled={busy} onClick={submit}>{busy && <Loader2 className="size-4 animate-spin" />} Mark paid</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function AdminPayouts({ payouts, sendEnabled, prefill = null }: { payouts: PayoutRow[]; sendEnabled: boolean; prefill?: OrganizerOption | null }) {
    const flash = usePage().props.flash as { success?: string } | undefined;
    const errors = usePage().props.errors as Record<string, string> | undefined;
    const confirm = useConfirm();
    const rm = (p: PayoutRow) => `${p.currency} ${p.amount.toFixed(2)}`;
    const [recording, setRecording] = useState(!!prefill);
    const [marking, setMarking] = useState<PayoutRow | null>(null);

    const sendChip = async (p: PayoutRow) => {
        if (await confirm({ title: 'Send this payout via CHIP?', description: `${rm(p)} to ${p.bank?.holder} · ${p.bank?.name} ${p.bank?.account}. This transfers real money.`, confirmText: 'Send now', destructive: true })) {
            router.post(`/admin/payouts/${p.reference}/send`, {}, { preserveScroll: true });
        }
    };
    const sync = (p: PayoutRow) => router.post(`/admin/payouts/${p.reference}/sync`, {}, { preserveScroll: true });

    return (
        <>
            <Head title="Payouts" />
            <div className="mx-auto w-full max-w-4xl flex-1 p-4">
                <div className="mb-1 flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-2xl font-bold tracking-tight">Payouts</h1>
                    <Button onClick={() => setRecording(true)}><Plus className="size-4" /> Record a payout</Button>
                </div>
                <p className="mb-4 text-sm text-muted-foreground">Pay organizers via CHIP Send, or record a bank transfer you made — with the slip as proof. You can also pay an organizer who hasn’t requested it.</p>
                {flash?.success && <div className="mb-4 rounded-lg border border-foreground bg-foreground p-3 text-sm text-background">{flash.success}</div>}
                {errors?.payout && <div className="mb-4 rounded-lg border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive">{errors.payout}</div>}

                {/* Payout methods status */}
                <div className="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div className="rounded-2xl border border-border bg-card p-4 shadow-sm">
                        <div className="flex items-center gap-2 font-medium"><HandCoins className="size-4" /> Manual bank transfer</div>
                        <div className="mt-1 flex items-center gap-1.5 text-xs text-[#2ec4b6]"><CheckCircle2 className="size-3.5" /> Always available</div>
                        <p className="mt-2 text-xs text-muted-foreground">Pay the organizer from your own bank, then “Mark paid” on their request with the transfer slip — or “Record a payout” if they never requested one.</p>
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
                        <p className="text-sm text-muted-foreground">No payouts yet.</p>
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
                                            {p.created_by && <span className="rounded-full bg-muted px-2 py-0.5 text-[11px] text-muted-foreground">Raised by {p.created_by}</span>}
                                        </div>
                                        <div className="mt-0.5 text-xs text-muted-foreground [overflow-wrap:anywhere]">{p.email} · {p.reference} · {p.created_by ? 'created' : 'requested'} {p.requested_at}</div>
                                        <div className="mt-1 text-xs text-muted-foreground">
                                            {p.bank ? <>Bank: {p.bank.name} · {p.bank.account} · {p.bank.holder}</> : <span className="text-amber-600">No bank details — organizer must add them for automated payout.</span>}
                                        </div>
                                        {p.status === 'paid' && (p.note || p.proof) && (
                                            <div className="mt-2 flex flex-wrap items-center gap-3 text-xs">
                                                {p.note && <span className="text-muted-foreground [overflow-wrap:anywhere]">“{p.note}”</span>}
                                                {p.proof && <a href={p.proof} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 font-medium underline-offset-2 hover:underline"><FileImage className="size-3.5" /> Transfer slip</a>}
                                            </div>
                                        )}
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
                                                <Button size="sm" variant="outline" onClick={() => setMarking(p)}>Mark paid manually</Button>
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

            {recording && <RecordPayout open={recording} prefill={prefill} onClose={() => setRecording(false)} />}
            <MarkPaid key={marking?.reference ?? 'none'} payout={marking} onClose={() => setMarking(null)} />
        </>
    );
}

AdminPayouts.layout = {
    breadcrumbs: [{ title: 'Payouts', href: '/admin/payouts' }],
};
