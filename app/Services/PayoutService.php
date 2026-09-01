<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payout;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PayoutService
{
    /**
     * The organizer's balance breakdown. Funds only become withdrawable AFTER the
     * event has taken place ("matured") — money from upcoming events is held as
     * pending clearance so refunds/cancellations can't leave the platform short.
     *
     * @return array{gross:float,fee_percent:float,fee:float,net:float,withdrawn:float,available:float,pending_clearance:float}
     */
    public function balanceFor(User $organizer): array
    {
        $feePercent = (float) Setting::get('platform_fee_percent', config('droprsvp.platform_fee_percent'));
        $netOf = fn (float $gross) => round($gross - round($gross * $feePercent / 100, 2), 2);

        $eventIds = $organizer->events()->pluck('id');
        $gross = (float) Order::whereIn('event_id', $eventIds)->where('status', 'paid')->sum('total');

        // An event's takings mature once it has happened: ends_at (or starts_at when
        // there's no end) is in the past; date-less events mature immediately.
        $maturedIds = $organizer->events()->where(function ($q) {
            $q->where(fn ($w) => $w->whereNotNull('ends_at')->where('ends_at', '<=', now()))
                ->orWhere(fn ($w) => $w->whereNull('ends_at')->whereNotNull('starts_at')->where('starts_at', '<=', now()))
                ->orWhere(fn ($w) => $w->whereNull('ends_at')->whereNull('starts_at'));
        })->pluck('id');
        $maturedGross = (float) Order::whereIn('event_id', $maturedIds)->where('status', 'paid')->sum('total');

        $net = $netOf($gross);
        $maturedNet = $netOf($maturedGross);

        // Everything already requested or paid out is no longer available.
        $withdrawn = (float) Payout::where('user_id', $organizer->id)
            ->whereIn('status', ['pending', 'paid'])
            ->sum('amount');

        return [
            'gross' => $gross,
            'fee_percent' => $feePercent,
            'fee' => round($gross * $feePercent / 100, 2),
            'net' => $net,
            'withdrawn' => round($withdrawn, 2),
            'available' => max(0.0, round($maturedNet - $withdrawn, 2)),
            'pending_clearance' => max(0.0, round($net - $maturedNet, 2)),
        ];
    }

    /** Request a payout for the full available balance. */
    public function request(User $organizer): Payout
    {
        $available = $this->balanceFor($organizer)['available'];

        if ($available <= 0) {
            throw ValidationException::withMessages(['payout' => 'You have no funds available to pay out.']);
        }

        return Payout::create([
            'user_id' => $organizer->id,
            'reference' => $this->uniqueReference(),
            'amount' => $available,
            'currency' => 'MYR',
            'status' => 'pending',
            'requested_at' => now(),
        ]);
    }

    /** Superadmin marks a pending payout as paid (idempotent). */
    public function markPaid(Payout $payout, ?string $method = null, ?string $note = null): void
    {
        if ($payout->status === 'paid') {
            return;
        }
        $payout->update(['status' => 'paid', 'paid_at' => now(), 'method' => $method, 'note' => $note]);
    }

    private function uniqueReference(): string
    {
        do {
            $ref = 'PO-'.strtoupper(Str::random(6));
        } while (Payout::where('reference', $ref)->exists());

        return $ref;
    }
}
