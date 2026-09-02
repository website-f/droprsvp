<?php

namespace App\Services;

use App\Mail\PayoutPaidMail;
use App\Models\Order;
use App\Models\Payout;
use App\Models\User;
use App\Support\PlatformFee;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PayoutService
{
    /**
     * The organizer's balance breakdown. Funds only become withdrawable AFTER the
     * event has taken place ("matured") — money from upcoming events is held as
     * pending clearance so refunds/cancellations can't leave the platform short.
     *
     * @return array{gross:float,fee_percent:float,fee_type:string,fee_label:string,fee:float,net:float,withdrawn:float,available:float,pending_clearance:float}
     */
    public function balanceFor(User $organizer): array
    {
        $eventIds = $organizer->events()->pluck('id');
        $paid = Order::whereIn('event_id', $eventIds)->where('status', 'paid');
        $gross = (float) (clone $paid)->sum('total');
        $grossOrders = (clone $paid)->count();

        // An event's takings mature once it has happened: ends_at (or starts_at when
        // there's no end) is in the past; date-less events mature immediately.
        $maturedIds = $organizer->events()->where(function ($q) {
            $q->where(fn ($w) => $w->whereNotNull('ends_at')->where('ends_at', '<=', now()))
                ->orWhere(fn ($w) => $w->whereNull('ends_at')->whereNotNull('starts_at')->where('starts_at', '<=', now()))
                ->orWhere(fn ($w) => $w->whereNull('ends_at')->whereNull('starts_at'));
        })->pluck('id');
        $maturedPaid = Order::whereIn('event_id', $maturedIds)->where('status', 'paid');
        $maturedGross = (float) (clone $maturedPaid)->sum('total');
        $maturedOrders = (clone $maturedPaid)->count();

        // Fee may be a % of gross or a flat amount per paid order.
        $fee = PlatformFee::on($gross, $grossOrders);
        $net = round($gross - $fee, 2);
        $maturedNet = round($maturedGross - PlatformFee::on($maturedGross, $maturedOrders), 2);

        // Everything already requested or paid out is no longer available.
        $withdrawn = (float) Payout::where('user_id', $organizer->id)
            ->whereIn('status', ['pending', 'paid'])
            ->sum('amount');

        return [
            'gross' => $gross,
            'fee_percent' => PlatformFee::percent(),
            'fee_type' => PlatformFee::type(),
            'fee_label' => PlatformFee::label(),
            'fee' => $fee,
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
        $this->notifyPaid($payout);
    }

    /** Email the organizer that their payout went through (non-fatal). */
    private function notifyPaid(Payout $payout): void
    {
        $email = $payout->user?->email;
        if (! $email) {
            return;
        }
        try {
            Mail::to($email)->send(new PayoutPaidMail($payout));
        } catch (\Throwable $e) {
            report($e);
        }
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

        if ($completed) {
            $this->notifyPaid($payout);
        }
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

        $wasPaid = $payout->status === 'paid';
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

        if (! $wasPaid && $payout->status === 'paid') {
            $this->notifyPaid($payout);
        }
    }

    private function uniqueReference(): string
    {
        do {
            $ref = 'PO-'.strtoupper(Str::random(6));
        } while (Payout::where('reference', $ref)->exists());

        return $ref;
    }
}
