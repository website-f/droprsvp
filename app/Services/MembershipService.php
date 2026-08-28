<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Payments\FakePaymentGateway;
use App\Services\Payments\HitPayGateway;
use App\Services\Payments\PaymentGateway;
use Illuminate\Support\Str;

/**
 * Premium membership: a user pays to unlock premium benefits for N days.
 * Reuses the payment gateway (fake settles instantly in dev; HitPay in prod).
 */
class MembershipService
{
    public function price(): float
    {
        return (float) Setting::get('premium_price', config('droprsvp.premium_price'));
    }

    public function days(): int
    {
        return (int) Setting::get('premium_days', config('droprsvp.premium_days'));
    }

    /** Start a subscription. Returns a gateway URL, or null when it settled instantly. */
    public function start(User $user, PaymentGateway $gateway): ?string
    {
        $sub = Subscription::create([
            'reference' => $this->reference(),
            'user_id' => $user->id,
            'amount' => $this->price(),
            'days' => $this->days(),
            'status' => 'pending',
        ]);

        if ($sub->amount <= 0 || $gateway instanceof FakePaymentGateway) {
            $this->settle($sub);

            return null;
        }

        if ($gateway instanceof HitPayGateway) {
            $res = $gateway->createRequest([
                'amount' => number_format((float) $sub->amount, 2, '.', ''),
                'currency' => config('services.hitpay.currency', 'MYR'),
                'reference_number' => $sub->reference,
                'redirect_url' => route('premium.return'),
                'webhook' => route('subscriptions.webhook'),
                'name' => $user->name,
                'email' => $user->email,
            ]);
            $sub->update(['payment_ref' => $res['id'] ?? null]);

            return $res['url'];
        }

        $this->settle($sub);

        return null;
    }

    /** Mark a subscription paid and extend the user's premium window. Idempotent. */
    public function settle(Subscription $sub, ?string $ref = null): void
    {
        if ($sub->status === 'paid') {
            return;
        }

        $sub->update(['status' => 'paid', 'paid_at' => now(), 'payment_ref' => $ref ?: $sub->payment_ref]);

        $user = $sub->user;
        $base = $user->isPremium() ? $user->premium_until : now();
        $user->premium_until = $base->copy()->addDays($sub->days);
        $user->save();
    }

    private function reference(): string
    {
        do {
            $ref = 'SUB-'.strtoupper(Str::random(6));
        } while (Subscription::where('reference', $ref)->exists());

        return $ref;
    }
}
