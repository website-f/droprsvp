<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Promotion;
use App\Models\Setting;
use App\Models\User;
use App\Services\Payments\ChipGateway;
use App\Services\Payments\FakePaymentGateway;
use App\Services\Payments\PaymentGateway;
use Illuminate\Support\Str;

/**
 * Boosting/promotion: an organizer pays the platform to feature their event for
 * a number of days. Reuses the payment gateway (fake settles instantly in dev;
 * CHIP in production), and keeps its money separate from ticket revenue.
 */
class PromotionService
{
    public function price(): float
    {
        return (float) Setting::get('boost_price', config('droprsvp.boost_price'));
    }

    public function days(): int
    {
        return (int) Setting::get('boost_days', config('droprsvp.boost_days'));
    }

    /**
     * Start a boost purchase. Returns a redirect URL to the gateway, or null when
     * it settled instantly (free / dev fake gateway).
     */
    public function start(Event $event, User $user, PaymentGateway $gateway): ?string
    {
        $promo = Promotion::create([
            'reference' => $this->reference(),
            'event_id' => $event->id,
            'user_id' => $user->id,
            'amount' => $this->price(),
            'days' => $this->days(),
            'status' => 'pending',
        ]);

        if ($promo->amount <= 0 || $gateway instanceof FakePaymentGateway) {
            $this->settle($promo);

            return null;
        }

        if ($gateway instanceof ChipGateway) {
            $res = $gateway->createRequest([
                'amount' => (float) $promo->amount,
                'currency' => config('services.chip.currency', 'MYR'),
                'reference_number' => $promo->reference,
                'redirect_url' => route('host.events.promote.return', $event),
                'webhook' => route('promotions.webhook'),
                'name' => $user->name,
                'email' => $user->email,
                'description' => 'Event boost · '.$event->title,
            ]);
            $promo->update(['payment_ref' => $res['id'] ?? null]);

            return $res['url'];
        }

        // Unknown gateway — settle to avoid blocking (should not happen).
        $this->settle($promo);

        return null;
    }

    /** Mark a promotion paid and extend the event's boost window. Idempotent. */
    public function settle(Promotion $promo, ?string $ref = null): void
    {
        if ($promo->status === 'paid') {
            return;
        }

        $promo->update(['status' => 'paid', 'paid_at' => now(), 'payment_ref' => $ref ?: $promo->payment_ref]);

        $event = $promo->event;
        $base = $event->isBoosted() ? $event->boosted_until : now();
        $event->update(['boosted_until' => $base->copy()->addDays($promo->days)]);
    }

    private function reference(): string
    {
        do {
            $ref = 'BOOST-'.strtoupper(Str::random(6));
        } while (Promotion::where('reference', $ref)->exists());

        return $ref;
    }
}
