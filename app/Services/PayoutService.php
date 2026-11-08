<?php

namespace App\Services;

use App\Mail\PayoutPaidMail;
use App\Models\Event;
use App\Models\Order;
use App\Models\Payout;
use App\Models\User;
use App\Services\Payments\ChipSendGateway;
use App\Support\PlatformFee;
use Illuminate\Support\Facades\DB;
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
     * @return array{gross:float,fee_label:string,net:float,withdrawn:float,available:float,pending_clearance:float}
     */
    public function balanceFor(User $organizer): array
    {
        $eventIds = $organizer->events()->pluck('id');
        // The organizer receives what the buyer paid (ticket revenue + any tax)
        // less the platform's commission, which comes out of their share — the
        // buyer pays no booking fee. Refunds (partial keep the order 'paid') are
        // netted out too.
        $paid = Order::whereIn('event_id', $eventIds)->where('status', 'paid');
        $net = (float) (clone $paid)->sum(DB::raw('total - fees - refunded_amount'));

        // An event's takings mature once it has happened: ends_at (or starts_at when
        // there's no end) is in the past; date-less events mature immediately.
        $maturedIds = $organizer->events()->where(function ($q) {
            $q->where(fn ($w) => $w->whereNotNull('ends_at')->where('ends_at', '<=', now()))
                ->orWhere(fn ($w) => $w->whereNull('ends_at')->whereNotNull('starts_at')->where('starts_at', '<=', now()))
                ->orWhere(fn ($w) => $w->whereNull('ends_at')->whereNull('starts_at'));
        })->pluck('id');
        $maturedNet = (float) Order::whereIn('event_id', $maturedIds)->where('status', 'paid')->sum(DB::raw('total - fees - refunded_amount'));

        // Everything already requested, in transfer or paid out is no longer
        // available. 'processing' (a CHIP Send transfer not yet confirmed) was
        // missing here, so money on its way to the organizer showed as
        // available again and could be requested a second time.
        $withdrawn = (float) Payout::where('user_id', $organizer->id)
            ->whereIn('status', ['pending', 'processing', 'paid'])
            ->sum('amount');

        return [
            'gross' => round($net, 2), // organizer's ticket revenue (no fee deducted)
            'fee_label' => PlatformFee::label($organizer),
            'net' => round($net, 2),
            'withdrawn' => round($withdrawn, 2),
            'available' => max(0.0, round($maturedNet - $withdrawn, 2)),
            'pending_clearance' => max(0.0, round($net - $maturedNet, 2)),
        ];
    }

    /**
     * When each event's takings unlock — the "why can't I withdraw yet?" answer.
     *
     * balanceFor() gives the totals, but a single "held until events end" figure
     * left organizers guessing which money, and until when. Their payout button
     * simply greyed out at RM 0.00 with nothing saying why, which reads as broken.
     * This lists every event that has taken money, what it is worth to them, and
     * the date it becomes withdrawable — the same maturity rule balanceFor uses:
     * the event's end (its start when it has no end), immediately when undated.
     *
     * Ordered so the next thing to unlock is first.
     *
     * @return list<array{title: string, slug: string, net: float, available: bool, releases_at: ?string, releases_on: ?string}>
     */
    public function releaseSchedule(User $organizer): array
    {
        $events = $organizer->events()
            ->withSum(['orders as net' => fn ($q) => $q->where('status', 'paid')], DB::raw('total - fees - refunded_amount'))
            ->get(['id', 'title', 'slug', 'starts_at', 'ends_at', 'timezone']);

        return $events
            ->filter(fn ($e) => (float) $e->net > 0)
            ->map(function ($e) {
                $releasesAt = $e->ends_at ?? $e->starts_at;

                return [
                    'title' => $e->title,
                    'slug' => $e->slug,
                    'net' => round((float) $e->net, 2),
                    'available' => $releasesAt === null || $releasesAt->isPast(),
                    'releases_at' => $releasesAt?->toIso8601String(),
                    // In the event's own timezone: "after your event ends" means
                    // when it ends where it happens.
                    'releases_on' => $releasesAt?->setTimezone($e->timezone ?: config('app.timezone'))->format('j M Y, g:i A'),
                ];
            })
            // Still-clearing first, soonest first; then what is already free.
            ->sortBy(fn ($row) => [$row['available'] ? 1 : 0, $row['releases_at'] ?? ''])
            ->values()
            ->all();
    }

    /**
     * One event's money, line by line: what buyers paid, what went back as
     * refunds, the platform's commission and the organizer's share — the
     * figures behind "how much do we owe them for this event".
     *
     * Same arithmetic as balanceFor(): the organizer's share is
     * total − fees − refunded_amount on paid orders. A fully refunded order
     * keeps its total and refunded_amount (its fee is zeroed), so it shows in
     * sales and in refunds and nets to nothing.
     *
     * @return array<string, mixed>
     */
    public function eventStatement(Event $event): array
    {
        $orders = Order::where('event_id', $event->id)->whereIn('status', ['paid', 'refunded']);

        $sums = (clone $orders)->selectRaw(
            'COUNT(*) as orders, COALESCE(SUM(total), 0) as sales, COALESCE(SUM(refunded_amount), 0) as refunds, '
            .'COALESCE(SUM(fees), 0) as fees, COALESCE(SUM(tax), 0) as tax, COALESCE(SUM(discount), 0) as discounts, '
            ."SUM(CASE WHEN status = 'refunded' THEN 1 ELSE 0 END) as refunded_orders, "
            .'SUM(CASE WHEN total > 0 THEN 1 ELSE 0 END) as paying_orders'
        )->first();

        $sales = round((float) $sums->sales, 2);
        $refunds = round((float) $sums->refunds, 2);
        $fees = round((float) $sums->fees, 2);
        $netSales = round($sales - $refunds, 2);
        $releasesAt = $event->ends_at ?? $event->starts_at;
        $organizer = $event->user;

        // Per ticket type, from the order lines. The commission is charged per
        // order, so it is shared out over each order's lines by value.
        $types = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.event_id', $event->id)
            ->where('orders.status', 'paid')
            ->groupBy('order_items.name')
            ->selectRaw('order_items.name as name, SUM(order_items.quantity) as tickets, SUM(order_items.line_total) as sales, '
                .'SUM(CASE WHEN orders.subtotal > 0 THEN orders.fees * order_items.line_total / orders.subtotal ELSE 0 END) as fees')
            ->orderByDesc('sales')
            ->get()
            ->map(fn ($t) => [
                'name' => $t->name,
                'tickets' => (int) $t->tickets,
                'sales' => round((float) $t->sales, 2),
                'fees' => round((float) $t->fees, 2),
                'net' => round((float) $t->sales - (float) $t->fees, 2),
            ])
            ->all();

        return [
            'orders' => (int) $sums->orders,
            'paying_orders' => (int) $sums->paying_orders,
            'refunded_orders' => (int) $sums->refunded_orders,
            'sales' => $sales,
            'discounts' => round((float) $sums->discounts, 2),
            'tax' => round((float) $sums->tax, 2),
            'refunds' => $refunds,
            'net_sales' => $netSales,
            'fees' => $fees,
            // What the commission came to as a share of the money kept.
            'effective_rate' => $netSales > 0 ? round($fees / $netSales * 100, 1) : 0.0,
            'organizer_share' => round($netSales - $fees, 2),
            'fee' => PlatformFee::toArray($organizer),
            'released' => $releasesAt === null || $releasesAt->isPast(),
            'releases_on' => $releasesAt?->setTimezone($event->timezone ?: config('app.timezone'))->format('j M Y, g:i A'),
            'ticket_types' => $types,
        ];
    }

    /**
     * Everything between the platform and one organizer, across all their
     * events: their share, what has been paid, what is waiting on a request,
     * what they can withdraw now and what is still clearing — and so what the
     * platform still owes them in total.
     *
     * @return array<string, mixed>
     */
    public function organizerStatement(User $organizer): array
    {
        $balance = $this->balanceFor($organizer);

        $payouts = Payout::where('user_id', $organizer->id)->latest('requested_at')->latest('id')->get();
        $paid = round((float) $payouts->where('status', 'paid')->sum('amount'), 2);
        $requested = round((float) $payouts->whereIn('status', ['pending', 'processing'])->sum('amount'), 2);

        return [
            'name' => $organizer->organizerProfile?->business_name ?: $organizer->name,
            'email' => $organizer->email,
            'events' => $organizer->events()->count(),
            'earned' => $balance['net'],
            'paid_out' => $paid,
            'requested' => $requested,
            'available' => $balance['available'],
            'clearing' => $balance['pending_clearance'],
            // Owed = earned − already paid. Requested-but-unpaid money is still owed.
            'owed' => max(0.0, round($balance['net'] - $paid, 2)),
            'fee' => PlatformFee::toArray($organizer),
            'payouts' => $payouts->take(10)->map(fn (Payout $p) => [
                'reference' => $p->reference,
                'amount' => round((float) $p->amount, 2),
                'status' => $p->status,
                'method' => $p->method,
                'requested_on' => $p->requested_at?->format('j M Y'),
                'paid_on' => $p->paid_at?->format('j M Y'),
            ])->values()->all(),
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
    public function sendViaChip(Payout $payout, ChipSendGateway $send): void
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
        $completed = $result['state'] === ChipSendGateway::DONE;

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
    public function syncChipStatus(Payout $payout, ChipSendGateway $send): void
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
        if ($state === ChipSendGateway::DONE) {
            $payout->status = 'paid';
            $payout->paid_at = now();
        } elseif (in_array($state, ChipSendGateway::FAILED, true)) {
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
