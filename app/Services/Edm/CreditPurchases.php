<?php

namespace App\Services\Edm;

use App\Models\EdmCreditPurchase;
use App\Models\User;
use App\Services\Payments\ChipGateway;
use App\Services\Payments\FakePaymentGateway;
use App\Services\Payments\PaymentGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Buying email credits: a pack is paid through CHIP like an event boost, and
 * the credits land in the organizer's ledger when the payment settles —
 * by the webhook or the return page, whichever comes first, exactly once.
 */
class CreditPurchases
{
    /** @return list<array{key: string, name: string, credits: int, price: float}> */
    public static function packs(): array
    {
        return array_values((array) config('edm.organizers.packs', []));
    }

    public static function pack(string $key): ?array
    {
        return collect(self::packs())->firstWhere('key', $key);
    }

    /** Start a purchase. Returns the gateway URL, or null when it settled at once (dev). */
    public function start(User $organizer, string $packKey, PaymentGateway $gateway): ?string
    {
        $pack = self::pack($packKey) ?? throw new RuntimeException('That credit pack is not available.');

        $purchase = EdmCreditPurchase::create([
            'reference' => $this->reference(),
            'organizer_id' => $organizer->id,
            'pack' => $pack['key'],
            'credits' => (int) $pack['credits'],
            'amount' => (float) $pack['price'],
            'status' => 'pending',
        ]);

        if ((float) $purchase->amount <= 0 || $gateway instanceof FakePaymentGateway) {
            $this->settle($purchase);

            return null;
        }

        if ($gateway instanceof ChipGateway) {
            $res = $gateway->createRequest([
                'amount' => (float) $purchase->amount,
                'currency' => config('services.chip.currency', 'MYR'),
                'reference_number' => $purchase->reference,
                'redirect_url' => route('host.edm.credits.return', ['reference' => $purchase->reference]),
                'webhook' => route('edm-credits.webhook'),
                'name' => $organizer->name,
                'email' => $organizer->email,
                'description' => number_format($purchase->credits).' email credits · DropRSVP',
            ]);
            $purchase->update(['payment_ref' => $res['id'] ?? null]);

            return $res['url'];
        }

        $this->settle($purchase);

        return null;
    }

    /** Mark paid and add the credits. Idempotent and race-safe. */
    public function settle(EdmCreditPurchase $purchase, ?string $ref = null, array $payment = []): void
    {
        DB::transaction(function () use ($purchase, $ref, $payment) {
            $locked = EdmCreditPurchase::whereKey($purchase->id)->lockForUpdate()->first();

            if (! $locked || $locked->status === 'paid') {
                return;
            }

            $locked->update([
                'status' => 'paid',
                'paid_at' => now(),
                'payment_ref' => $ref ?: $locked->payment_ref,
                'payment_method' => $payment['method'] ?? $locked->payment_method,
                'payment_brand' => $payment['brand'] ?? $locked->payment_brand,
            ]);

            Credits::grant($locked->organizer_id, (int) $locked->credits, 'purchase', number_format($locked->credits).' credits · '.$locked->reference, $locked->id);
        });
    }

    private function reference(): string
    {
        do {
            $ref = 'EDM-'.strtoupper(Str::random(8));
        } while (EdmCreditPurchase::where('reference', $ref)->exists());

        return $ref;
    }
}
