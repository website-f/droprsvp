import { Head } from '@inertiajs/react';
import { ArrowLeft, Download } from 'lucide-react';
import { LogoMark } from '@/components/brand';
import { Button } from '@/components/ui/button';

interface Item { description: string; qty: number; unit: number; total: number }
interface Receipt {
    kind: 'order' | 'payout';
    title: string;
    number: string;
    date: string | null;
    status: string;
    seller: { name: string; detail: string | null; logo: string | null; address?: string | null; tax_number?: string | null };
    party_label: string;
    party: { name: string | null; detail: string | null };
    context: string | null;
    items: Item[];
    subtotal: number;
    discount: number;
    tax: number;
    total: number;
    currency: string;
}

const initials = (n: string) => n.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]?.toUpperCase()).join('') || '?';

export default function ReceiptShow({ receipt, pdfUrl }: { receipt: Receipt; pdfUrl: string }) {
    const money = (n: number) => `${receipt.currency} ${n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    const statusTone = receipt.status === 'refunded' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800';

    return (
        <>
            <Head title={`${receipt.title} ${receipt.number}`} />
            <div className="min-h-screen bg-muted/40 py-8 print:bg-white print:py-0">
                {/* Toolbar (hidden when printing) */}
                <div className="mx-auto mb-4 flex max-w-3xl items-center justify-between px-6 print:hidden">
                    <button type="button" onClick={() => window.history.back()} className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft className="size-4" /> Back</button>
                    <Button asChild size="sm"><a href={pdfUrl}><Download className="size-4" /> Download PDF</a></Button>
                </div>

                {/* Document */}
                <div className="mx-auto max-w-3xl bg-background px-8 py-10 shadow-sm ring-1 ring-border print:max-w-none print:px-10 print:shadow-none print:ring-0 sm:rounded-2xl">
                    {/* Header */}
                    <div className="flex flex-col gap-6 border-b border-border pb-6 sm:flex-row sm:items-start sm:justify-between">
                        <div className="flex items-center gap-3">
                            {receipt.seller.logo
                                ? <img src={receipt.seller.logo} alt="" className="size-12 rounded-xl border border-border object-cover" />
                                : <span className="flex size-12 items-center justify-center rounded-xl bg-foreground text-sm font-bold text-background">{initials(receipt.seller.name)}</span>}
                            <div>
                                <div className="text-lg font-bold tracking-tight">{receipt.seller.name}</div>
                                {receipt.seller.detail && <div className="text-sm text-muted-foreground">{receipt.seller.detail}</div>}
                                {receipt.seller.address && <div className="text-sm whitespace-pre-line text-muted-foreground">{receipt.seller.address}</div>}
                                {receipt.seller.tax_number && <div className="text-sm text-muted-foreground">SST/Tax No: {receipt.seller.tax_number}</div>}
                            </div>
                        </div>
                        <div className="sm:text-right">
                            <div className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{receipt.title}</div>
                            <div className="text-lg font-bold tracking-tight">{receipt.number}</div>
                            <div className="mt-1 text-sm text-muted-foreground">{receipt.date}</div>
                            <span className={`mt-2 inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold capitalize ${statusTone}`}>{receipt.status}</span>
                        </div>
                    </div>

                    {/* Parties */}
                    <div className="grid gap-6 py-6 sm:grid-cols-2">
                        <div>
                            <div className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{receipt.party_label}</div>
                            <div className="mt-1 text-sm font-medium">{receipt.party.name || '—'}</div>
                            {receipt.party.detail && <div className="text-sm text-muted-foreground">{receipt.party.detail}</div>}
                        </div>
                        {receipt.context && (
                            <div className="sm:text-right">
                                <div className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">For</div>
                                <div className="mt-1 text-sm font-medium">{receipt.context}</div>
                            </div>
                        )}
                    </div>

                    {/* Line items */}
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-y border-border text-left text-xs uppercase tracking-wide text-muted-foreground">
                                    <th className="py-2.5 pr-3 font-semibold">Description</th>
                                    <th className="py-2.5 px-3 text-right font-semibold">Qty</th>
                                    <th className="py-2.5 px-3 text-right font-semibold">Unit</th>
                                    <th className="py-2.5 pl-3 text-right font-semibold">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                {receipt.items.map((it, i) => (
                                    <tr key={i} className="border-b border-border/60">
                                        <td className="py-3 pr-3">{it.description}</td>
                                        <td className="py-3 px-3 text-right tabular-nums">{it.qty}</td>
                                        <td className="py-3 px-3 text-right tabular-nums">{money(it.unit)}</td>
                                        <td className="py-3 pl-3 text-right tabular-nums">{money(it.total)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {/* Totals */}
                    <div className="mt-5 flex justify-end">
                        <div className="w-full max-w-xs space-y-1.5 text-sm">
                            <div className="flex justify-between text-muted-foreground"><span>Subtotal</span><span className="tabular-nums">{money(receipt.subtotal)}</span></div>
                            {receipt.discount > 0 && <div className="flex justify-between text-emerald-600 dark:text-emerald-400"><span>Discount</span><span className="tabular-nums">− {money(receipt.discount)}</span></div>}
                            {receipt.tax > 0 && <div className="flex justify-between text-muted-foreground"><span>Tax</span><span className="tabular-nums">{money(receipt.tax)}</span></div>}
                            <div className="flex justify-between border-t border-border pt-2 text-base font-bold"><span>Total</span><span className="tabular-nums">{money(receipt.total)}</span></div>
                        </div>
                    </div>

                    {/* Powered by */}
                    <div className="mt-10 flex items-center justify-center gap-2 border-t border-border pt-6 text-xs text-muted-foreground">
                        powered by <LogoMark className="size-4" /> <span className="font-semibold text-foreground">DropRSVP</span>
                    </div>
                </div>
            </div>
        </>
    );
}
