import { Loader2, ShieldCheck } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';

export interface SummaryLine { label: string; value: string }

/** Order-summary confirmation shown before any hand-off to the payment gateway. */
export function PaymentConfirm({
    open, onOpenChange, heading, lines, total, currency = 'RM', note, processing, onProceed, ctaLabel = 'Proceed to payment',
}: {
    open: boolean;
    onOpenChange: (v: boolean) => void;
    heading: string;
    lines: SummaryLine[];
    total: number;
    currency?: string;
    note?: ReactNode;
    processing: boolean;
    onProceed: () => void;
    ctaLabel?: string;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader><DialogTitle>Order summary</DialogTitle></DialogHeader>
                <div className="grid gap-3">
                    <div className="font-medium">{heading}</div>
                    <div className="grid gap-2 rounded-xl border border-border bg-muted/30 p-3 text-sm">
                        {lines.map((l, i) => (
                            <div key={i} className="flex items-center justify-between gap-4">
                                <span className="text-muted-foreground">{l.label}</span>
                                <span className="font-medium">{l.value}</span>
                            </div>
                        ))}
                        <div className="mt-1 flex items-center justify-between border-t border-border pt-2">
                            <span className="font-semibold">Total</span>
                            <span className="text-lg font-bold">{currency} {total.toFixed(2)}</span>
                        </div>
                    </div>
                    {note && <p className="text-xs text-muted-foreground">{note}</p>}
                </div>
                <DialogFooter className="gap-2 sm:gap-2">
                    <Button variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>Cancel</Button>
                    <Button onClick={onProceed} disabled={processing}>{processing ? 'Starting…' : ctaLabel}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** Full-screen veil while the browser is being handed to the payment gateway. */
export function PaymentRedirecting({ show }: { show: boolean }) {
    if (!show) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-[80] flex flex-col items-center justify-center gap-4 bg-background/95 backdrop-blur">
            <Loader2 className="size-9 animate-spin text-foreground" />
            <div className="text-center">
                <p className="text-sm font-semibold">Redirecting to secure payment…</p>
                <p className="mt-1 flex items-center justify-center gap-1 text-xs text-muted-foreground"><ShieldCheck className="size-3.5" /> Please don’t close this window.</p>
            </div>
        </div>
    );
}
