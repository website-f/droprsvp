import { useForm } from '@inertiajs/react';
import { RotateCcw } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';

export interface FeeRate {
    percent: number;
    flat: number;
    label: string;
    custom: boolean;
}
export interface FeeTarget {
    id: number;
    name: string;
    fee: FeeRate;
}

interface Props {
    /** The organizer being edited — null closes the dialog. */
    target: FeeTarget | null;
    /** The platform-wide rate, shown for comparison and used as the reset target. */
    global: FeeRate;
    onClose: () => void;
}

const input =
    'h-10 w-full rounded-lg border border-input bg-card px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';
const rm = (n: number) => `RM${n.toFixed(2)}`;

/** Same maths as PlatformFee::on() — the higher of the % or the flat amount. */
const feeOn = (base: number, percent: number, flat: number) =>
    base <= 0
        ? 0
        : Math.round(Math.max((base * percent) / 100, flat) * 100) / 100;

/**
 * Move one organizer onto their own booking fee, or hand them back to the global
 * rate. Shared by the per-organizer fee table and the user's admin detail page —
 * both post to the same endpoint, so the two entry points can't drift.
 */
export function OrganizerFeeDialog({ target, global, onClose }: Props) {
    return (
        <Dialog open={!!target} onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="sm:max-w-md">
                {/* Keyed on the organizer so the form reloads with their rate each time. */}
                {target && (
                    <FeeForm
                        key={target.id}
                        target={target}
                        global={global}
                        onClose={onClose}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function FeeForm({
    target,
    global,
    onClose,
}: {
    target: FeeTarget;
    global: FeeRate;
    onClose: () => void;
}) {
    const form = useForm({
        fee_percent: String(target.fee.percent),
        fee_flat: String(target.fee.flat),
    });
    const { data, setData, processing, errors } = form;

    const percent = Number(data.fee_percent) || 0;
    const flat = Number(data.fee_flat) || 0;

    const save = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(`/admin/organizer-fees/${target.id}`, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };
    const reset = () =>
        form.delete(`/admin/organizer-fees/${target.id}`, {
            preserveScroll: true,
            onSuccess: onClose,
        });

    return (
        <form onSubmit={save} className="grid gap-4">
            <DialogHeader>
                <DialogTitle>Booking fee — {target.name}</DialogTitle>
                <DialogDescription>
                    Charge this organizer’s buyers a rate of your own instead of
                    the platform default. It applies to new orders on their
                    events only — orders already placed keep the fee they were
                    charged.
                </DialogDescription>
            </DialogHeader>

            <div className="grid gap-1.5">
                <Label>Fee — the higher of % or flat RM</Label>
                <div className="flex flex-wrap items-center gap-2">
                    <div className="relative w-32">
                        <input
                            type="number"
                            min={0}
                            max={100}
                            step="0.1"
                            aria-label="Fee percent"
                            className={`${input} pr-8`}
                            value={data.fee_percent}
                            onChange={(e) =>
                                setData('fee_percent', e.target.value)
                            }
                        />
                        <span className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-muted-foreground">
                            %
                        </span>
                    </div>
                    <span className="text-sm font-medium text-muted-foreground">
                        or
                    </span>
                    <div className="relative w-32">
                        <span className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-muted-foreground">
                            RM
                        </span>
                        <input
                            type="number"
                            min={0}
                            step="0.5"
                            aria-label="Fee flat amount"
                            className={`${input} pl-10`}
                            value={data.fee_flat}
                            onChange={(e) =>
                                setData('fee_flat', e.target.value)
                            }
                        />
                    </div>
                </div>
                {(errors.fee_percent || errors.fee_flat) && (
                    <p className="text-xs text-destructive">
                        {errors.fee_percent ?? errors.fee_flat}
                    </p>
                )}
            </div>

            {/* What the new rate actually collects, so the admin can sanity-check it. */}
            <div className="grid gap-1 rounded-lg border border-border bg-muted/30 p-3 text-xs">
                <div className="font-medium text-foreground">
                    A buyer would pay
                </div>
                <div className="flex justify-between text-muted-foreground">
                    <span>On a RM29 ticket</span>
                    <span className="font-medium text-foreground">
                        {rm(feeOn(29, percent, flat))}
                    </span>
                </div>
                <div className="flex justify-between text-muted-foreground">
                    <span>On a RM200 ticket</span>
                    <span className="font-medium text-foreground">
                        {rm(feeOn(200, percent, flat))}
                    </span>
                </div>
                <div className="mt-1 border-t border-border pt-1.5 text-muted-foreground">
                    Global rate: {global.label}
                </div>
            </div>

            <DialogFooter className="mt-1 gap-2 sm:justify-between">
                {target.fee.custom ? (
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={reset}
                        disabled={processing}
                        className="text-muted-foreground"
                    >
                        <RotateCcw className="size-4" /> Use global fee
                    </Button>
                ) : (
                    <span />
                )}
                <div className="flex gap-2">
                    <Button type="button" variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing}>
                        {processing ? 'Saving…' : 'Save fee'}
                    </Button>
                </div>
            </DialogFooter>
        </form>
    );
}
