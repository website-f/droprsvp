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
        $payout->update(['status' => 'paid', 'paid_at' => now(), 'method' => $method ?? 'Manual', 'note' => $note]);
    }

    /**
     * Superadmin sends a payout automatically via CHIP Send. Creates a bank
     * send instruction; marks the payout paid if CHIP settles instantly,
     * otherwise "processing" until the status webhook / sync confirms it.
     */
    public function sendViaChip(Payout $payout, \App\Services\Payments\ChipSendGateway $send): void
    {
        if ($payout->status === 'paid') {
            return;
        }
        if (! $send->configured()) {
            throw ValidationException::withMessages(['payout' => 'CHIP Send is not configured. Add the CHIP Send API keys or pay this out manually.']);
        }
        $organizer = $payout->user;
        if (! $organizer?->payout_bank_code || ! $organizer?->payout_bank_account_number || ! $organizer?->payout_bank_account_name) {
            throw ValidationException::withMessages(['payout' => 'This organizer hasn’t added their bank details yet — they must add them before an automated payout, or pay them manually.']);
        }

        $result = $send->send($payout);
        $completed = $result['state'] === \App\Services\Payments\ChipSendGateway::DONE;

        $payout->update([
            'method' => 'CHIP Send',
            'chip_send_id' => $result['id'],
            'chip_send_state' => $result['state'],
            'status' => $completed ? 'paid' : 'processing',
            'paid_at' => $completed ? now() : null,
        ]);
    }

    /** Re-check a CHIP Send payout's status and settle/revert it. Idempotent. */
    public function syncChipStatus(Payout $payout, \App\Services\Payments\ChipSendGateway $send): void
    {
        if (! $payout->chip_send_id || $payout->status === 'paid') {
            return;
        }

        $state = $send->state((int) $payout->chip_send_id);
        if ($state === null) {
            return;
        }

        $payout->chip_send_state = $state;
        if ($state === \App\Services\Payments\ChipSendGateway::DONE) {
            $payout->status = 'paid';
            $payout->paid_at = now();
        } elseif (in_array($state, \App\Services\Payments\ChipSendGateway::FAILED, true)) {
            // The transfer failed — put it back in the queue so it can be retried or paid manually.
            $payout->status = 'pending';
            $payout->note = 'CHIP Send '.$state;
        } else {
            $payout->status = 'processing';
        }
        $payout->save();
    }

    private function uniqueReference(): string
    {
        do {
            $ref = 'PO-'.strtoupper(Str::random(6));
        } while (Payout::where('reference', $ref)->exists());

        return $ref;
    }
}
