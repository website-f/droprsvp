<?php

namespace App\Services;

use App\Mail\OrderRefundedMail;
use App\Mail\TicketsIssued;
use App\Models\Event;
use App\Models\Order;
use App\Models\Seat;
use App\Models\SeatSection;
use App\Models\TicketType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CheckoutService
{
    /**
     * Reserve inventory and create a PENDING order for the selected tickets.
     *
     * @param  array<int,array{ticket_type_id:int,quantity:int}>  $items
     *
     * @throws ValidationException when a ticket is unavailable / limits are broken
     */
    public function start(Event $event, array $items, ?int $userId = null, array $seatIds = []): Order
    {
        $wanted = collect($items)
            ->map(fn ($i) => ['ticket_type_id' => (int) $i['ticket_type_id'], 'quantity' => (int) $i['quantity']])
            ->filter(fn ($i) => $i['quantity'] > 0)
            ->values();
        $seatIds = collect($seatIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();

        if ($wanted->isEmpty() && $seatIds->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Select at least one ticket.']);
        }

        return DB::transaction(function () use ($event, $wanted, $seatIds, $userId) {
            $subtotal = 0.0;
            $currency = config('services.chip.currency', 'MYR');
            $lines = [];
            $heldSeatIds = [];

            foreach ($wanted as $row) {
                /** @var TicketType|null $tt */
                $tt = TicketType::where('event_id', $event->id)
                    ->whereKey($row['ticket_type_id'])
                    ->lockForUpdate()
                    ->first();

                if (! $tt || ! $tt->isOnSale()) {
                    throw ValidationException::withMessages(['items' => 'A selected ticket is no longer on sale.']);
                }
                if ($row['quantity'] < $tt->min_per_order || $row['quantity'] > $tt->max_per_order) {
                    throw ValidationException::withMessages(['items' => "“{$tt->name}” allows {$tt->min_per_order}–{$tt->max_per_order} per order."]);
                }
                $remaining = $tt->remaining();
                if ($remaining !== null && $row['quantity'] > $remaining) {
                    throw ValidationException::withMessages(['items' => "Only {$remaining} left for “{$tt->name}”."]);
                }

                $unit = $tt->kind === 'free' ? 0.0 : (float) $tt->price;
                $lineTotal = $unit * $row['quantity'];
                $subtotal += $lineTotal;
                $currency = $tt->currency;

                // Reserve the stock now so concurrent buyers can't oversell.
                $tt->increment('sold', $row['quantity']);

                $lines[] = ['ticket_type_id' => $tt->id, 'name' => $tt->name, 'unit_price' => $unit, 'quantity' => $row['quantity'], 'line_total' => $lineTotal];
            }

            // Reserved seats — one order line per seat, priced by its section.
            if ($seatIds->isNotEmpty()) {
                $seats = Seat::whereIn('id', $seatIds->all())->where('event_id', $event->id)->lockForUpdate()->get();
                if ($seats->count() !== $seatIds->count()) {
                    throw ValidationException::withMessages(['seats' => 'Some of those seats no longer exist.']);
                }
                foreach ($seats->groupBy('seat_section_id') as $sectionId => $sectionSeats) {
                    /** @var SeatSection|null $section */
                    $section = SeatSection::whereKey($sectionId)->first();
                    $tt = $section?->ticket_type_id ? TicketType::whereKey($section->ticket_type_id)->lockForUpdate()->first() : null;
                    if (! $section || ! $tt || ! $tt->isOnSale()) {
                        throw ValidationException::withMessages(['seats' => 'A selected section is no longer on sale.']);
                    }
                    $count = $sectionSeats->count();
                    $remaining = $tt->remaining();
                    if ($remaining !== null && $count > $remaining) {
                        throw ValidationException::withMessages(['seats' => "Only {$remaining} left in “{$section->name}”."]);
                    }

                    $unit = $tt->kind === 'free' ? 0.0 : (float) $tt->price;
                    $currency = $tt->currency;
                    $tt->increment('sold', $count);

                    foreach ($sectionSeats as $seat) {
                        if ($seat->status !== 'available') {
                            throw ValidationException::withMessages(['seats' => "Seat {$seat->label} was just taken — please pick another."]);
                        }
                        $subtotal += $unit;
                        $lines[] = [
                            'ticket_type_id' => $tt->id, 'seat_section_id' => $section->id, 'seat_id' => $seat->id,
                            'seat_label' => $section->name.' · '.$seat->label,
                            'name' => "{$section->name} — {$seat->label}", 'unit_price' => $unit, 'quantity' => 1, 'line_total' => $unit,
                        ];
                        $heldSeatIds[] = $seat->id;
                    }
                }
            }

            // Tax is superadmin-configurable (0 by default → no change to totals).
            $taxPercent = (float) \App\Models\Setting::get('tax_percent', config('droprsvp.tax_percent', 0));
            $tax = round($subtotal * $taxPercent / 100, 2);

            $order = Order::create([
                'reference' => $this->uniqueReference(),
                'user_id' => $userId,
                'event_id' => $event->id,
                'status' => 'pending',
                'subtotal' => $subtotal,
                'tax' => $tax,
                'total' => $subtotal + $tax,
                'currency' => $currency,
            ]);
            $order->items()->createMany($lines);

            // Hold the specific seats for this order so nobody else can take them.
            if ($heldSeatIds) {
                Seat::whereIn('id', $heldSeatIds)->update(['status' => 'held', 'order_id' => $order->id]);
            }

            return $order;
        });
    }

    /**
     * Apply a discount code to a pending order, recomputing tax + total. Throws a
     * ValidationException (field: code) if the code is unknown or not redeemable.
     */
    public function applyDiscount(Order $order, string $code): void
    {
        abort_unless($order->status === 'pending', 410);

        $discount = \App\Models\DiscountCode::where('event_id', $order->event_id)
            ->whereRaw('LOWER(code) = ?', [mb_strtolower(trim($code))])
            ->first();

        $subtotal = (float) $order->subtotal;

        if (! $discount || ($reason = $discount->rejectionReason($subtotal)) !== null) {
            throw ValidationException::withMessages(['code' => $discount ? $reason : 'That code isn’t valid for this event.']);
        }

        $this->reprice($order, $discount->discountFor($subtotal), $discount->id);
    }

    /** Remove any applied discount from a pending order and restore full pricing. */
    public function clearDiscount(Order $order): void
    {
        abort_unless($order->status === 'pending', 410);
        $this->reprice($order, 0.0, null);
    }

    /** Recompute an order's tax + total from its subtotal minus a discount. */
    private function reprice(Order $order, float $discount, ?int $discountCodeId): void
    {
        $subtotal = (float) $order->subtotal;
        $discount = round(min($discount, $subtotal), 2);
        $taxable = max(0.0, $subtotal - $discount);

        $taxPercent = (float) \App\Models\Setting::get('tax_percent', config('droprsvp.tax_percent', 0));
        $tax = round($taxable * $taxPercent / 100, 2);

        $order->update([
            'discount' => $discount,
            'discount_code_id' => $discountCodeId,
            'tax' => $tax,
            'total' => round($taxable + $tax, 2),
        ]);
    }

    /** Mark an order paid, issue its tickets, and email the buyer. Idempotent + race-safe. */
    public function markPaid(Order $order, ?string $paymentRef = null): void
    {
        $newlyPaid = DB::transaction(function () use ($order, $paymentRef): bool {
            /** @var Order $locked */
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();
            if ($locked->status === 'paid') {
                return false; // already settled — don't double-issue
            }

            $locked->update([
                'status' => 'paid',
                'paid_at' => now(),
                'payment_ref' => $paymentRef ?: $locked->payment_ref,
            ]);

            // Count the redemption once the order actually settles.
            if ($locked->discount_code_id) {
                \App\Models\DiscountCode::whereKey($locked->discount_code_id)->lockForUpdate()->increment('redemptions');
            }

            foreach ($locked->items as $item) {
                for ($i = 0; $i < $item->quantity; $i++) {
                    $locked->tickets()->create([
                        'ticket_type_id' => $item->ticket_type_id,
                        'seat_section_id' => $item->seat_section_id,
                        'event_id' => $locked->event_id,
                        'attendee_name' => $locked->buyer_name,
                        'attendee_email' => $locked->buyer_email,
                        'status' => 'valid',
                        'seat_label' => $item->seat_label,
                    ]);
                }
            }

            // Confirm any held seats as sold.
            Seat::where('order_id', $locked->id)->where('status', 'held')->update(['status' => 'sold']);

            // Banquet events can auto-seat each new admission at a table with space.
            if ($locked->event?->auto_assign_tables) {
                app(\App\Services\TableAssignmentService::class)->assign($locked->event, $locked->tickets()->whereNull('seating_table_id')->get());
            }

            return true;
        });

        // Email the tickets exactly once, after settlement (outside the transaction).
        if ($newlyPaid && $order->fresh()->buyer_email) {
            $this->provisionBuyerAccount($order->fresh());
            $order->load(['event', 'tickets']);
            // Sent after the HTTP response so slow SMTP never delays checkout.
            defer(function () use ($order) {
                try {
                    Mail::to($order->buyer_email)->send(new TicketsIssued($order));
                } catch (\Throwable $e) {
                    report($e);
                }
            });
        }
    }

    /**
     * Give a guest buyer a home for their tickets: link the order to an existing
     * account with the same email, or auto-create one with a temporary password
     * (emailed to them) so they can log in to re-download tickets and follow the
     * organizer. New accounts must set their own password on first login.
     */
    private function provisionBuyerAccount(Order $order): void
    {
        if ($order->user_id || ! $order->buyer_email) {
            return; // already tied to an account (logged-in purchase)
        }

        $existing = \App\Models\User::where('email', $order->buyer_email)->first();
        if ($existing) {
            $order->update(['user_id' => $existing->id]);

            return;
        }

        try {
            $temp = \Illuminate\Support\Str::password(10);
            $user = new \App\Models\User([
                'name' => $order->buyer_name ?: 'Guest',
                'email' => $order->buyer_email,
                'phone' => $order->buyer_phone,
            ]);
            $user->password = \Illuminate\Support\Facades\Hash::make($temp);
            $user->email_verified_at = now(); // they received mail at this address
            $user->must_set_password = true;
            $user->save();
            $user->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'buyer', 'guard_name' => 'web']));

            $order->update(['user_id' => $user->id]);

            defer(fn () => Mail::to($user->email)->send(new \App\Mail\GuestAccountMail($user, $temp)));
        } catch (\Throwable $e) {
            report($e); // never block a paid order over account creation
        }
    }

    /**
     * Refund a paid order: void its tickets and release the seats back to stock.
     * Idempotent; call the gateway refund BEFORE this. Returns true if it flipped.
     */
    /**
     * Record a refund on a paid order. $amount refunds a partial sum (order stays
     * paid, tickets remain valid); null — or an amount covering the remaining
     * balance — is a FULL refund (status → refunded, tickets released). The gateway
     * refund is triggered by the caller; this only settles the local records.
     *
     * @return array{ok: bool, full: bool, amount: float}
     */
    public function refund(Order $order, ?float $amount = null): array
    {
        $result = DB::transaction(function () use ($order, $amount): array {
            /** @var Order $locked */
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();
            if ($locked->status !== 'paid') {
                return ['ok' => false, 'full' => false, 'amount' => 0.0]; // only settled orders
            }

            $remaining = max(0.0, round((float) $locked->total - (float) $locked->refunded_amount, 2));
            $amt = $amount === null ? $remaining : round(min((float) $amount, $remaining), 2);
            if ($amt <= 0) {
                return ['ok' => false, 'full' => false, 'amount' => 0.0];
            }

            $newRefunded = round((float) $locked->refunded_amount + $amt, 2);
            $full = $newRefunded >= (float) $locked->total - 0.001;

            if ($full) {
                $locked->update(['status' => 'refunded', 'refunded_at' => now(), 'refunded_amount' => $locked->total]);
                $locked->tickets()->whereIn('status', ['valid', 'checked_in'])->update(['status' => 'refunded']);
                $this->releaseStock($locked);
            } else {
                $locked->update(['refunded_amount' => $newRefunded]);
            }

            return ['ok' => true, 'full' => $full, 'amount' => $amt];
        });

        // Only a full refund flips the order + emails the "order refunded" notice
        // (partial refunds are communicated by the refund-request approval flow).
        if ($result['ok'] && $result['full'] && $order->fresh()->buyer_email) {
            try {
                $fresh = $order->fresh()->load('event');
                defer(fn () => Mail::to($fresh->buyer_email)->send(new OrderRefundedMail($fresh)));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $result;
    }

    /**
     * Buyer self-cancels a free registration before the event: void its tickets and
     * hand the seats back. Only valid for a settled free order (total 0); paid orders
     * must go through the refund flow. Idempotent. Returns true if it cancelled.
     */
    public function cancelFree(Order $order): bool
    {
        return DB::transaction(function () use ($order): bool {
            /** @var Order $locked */
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();
            if ($locked->status !== 'paid' || (float) $locked->total > 0) {
                return false;
            }

            $locked->update(['status' => 'cancelled']);
            $locked->tickets()->whereIn('status', ['valid', 'checked_in'])->update(['status' => 'cancelled']);
            $this->releaseStock($locked);

            return true;
        });
    }

    /** Release a still-pending order's reserved stock (abandoned / failed / cancelled). */
    public function release(Order $order): void
    {
        DB::transaction(function () use ($order) {
            /** @var Order $locked */
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();
            if ($locked->status !== 'pending') {
                return;
            }
            $this->releaseStock($locked);
            $locked->update(['status' => 'cancelled']);
        });
    }

    /** Give each line's reserved seats back to its ticket type (DB-portable, locked). */
    private function releaseStock(Order $order): void
    {
        foreach ($order->items as $item) {
            if ($item->ticket_type_id && $tt = TicketType::whereKey($item->ticket_type_id)->lockForUpdate()->first()) {
                $tt->update(['sold' => max(0, $tt->sold - (int) $item->quantity)]);
            }
        }
        // Free any seats this order held or owned (abandoned hold or refund).
        Seat::where('order_id', $order->id)->update(['status' => 'available', 'order_id' => null]);
    }

    private function uniqueReference(): string
    {
        // 10 uppercase alphanumerics (~50 bits) — the reference is a capability
        // token for guest checkout and is exposed to organizers, so it must not
        // be feasibly guessable/enumerable.
        do {
            $ref = 'DRSVP-'.strtoupper(Str::random(10));
        } while (Order::where('reference', $ref)->exists());

        return $ref;
    }
}
