import { CreditCard, Landmark, QrCode, Wallet } from 'lucide-react';

export interface PaymentInfo {
    method: string;
    label: string;
    icon: string;
    color: string;
    brand: string | null;
    brand_label: string | null;
    brand_color: string | null;
    logo: string | null;
}

const ICONS: Record<string, typeof Landmark> = {
    landmark: Landmark,
    'credit-card': CreditCard,
    'qr-code': QrCode,
    wallet: Wallet,
};

/**
 * How a transaction was paid — the bank, card scheme or wallet behind it.
 *
 * Three ways to render, in order of what we actually have:
 *   1. a logo file, if someone dropped one into public/img/payment/,
 *   2. otherwise the brand's name on its own colour,
 *   3. otherwise just the rail ("Card", "Online banking"), which is all the
 *      gateway told us.
 *
 * We ship no logo files: bank and card-scheme marks are trademarks with their
 * own usage rules, so that is the site owner's call, not ours. The coloured
 * name reads perfectly well until then.
 */
export function PaymentBadge({ payment }: { payment: PaymentInfo | null }) {
    if (!payment) {
        // Every row settled before we started recording this. An em dash is
        // honest; a guessed method would not be.
        return <span className="text-xs text-muted-foreground/50">—</span>;
    }

    const Icon = ICONS[payment.icon] ?? CreditCard;
    const tint = payment.brand_color ?? payment.color;

    return (
        <span className="inline-flex items-center gap-1.5">
            {payment.logo ? (
                <img src={payment.logo} alt="" className="h-4 w-auto max-w-[3.5rem] shrink-0 object-contain" />
            ) : (
                <span
                    className="flex size-5 shrink-0 items-center justify-center rounded"
                    style={{ backgroundColor: `${tint}22`, color: tint }}
                >
                    <Icon className="size-3" />
                </span>
            )}
            <span className="min-w-0">
                <span className="block truncate text-xs font-medium">{payment.brand_label ?? payment.label}</span>
                {payment.brand_label && (
                    <span className="block truncate text-[11px] text-muted-foreground">{payment.label}</span>
                )}
            </span>
        </span>
    );
}
