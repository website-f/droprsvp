<?php

namespace App\Services;

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
            $currency = config('services.hitpay.currency', 'MYR');
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

            return true;
        });

        // Email the tickets exactly once, after settlement (outside the transaction).
        if ($newlyPaid && $order->fresh()->buyer_email) {
            $order->load(['event', 'tickets']);
            Mail::to($order->buyer_email)->send(new TicketsIssued($order));
        }
    }

    /**
     * Refund a paid order: void its tickets and release the seats back to stock.
     * Idempotent; call the gateway refund BEFORE this. Returns true if it flipped.
     */
    public function refund(Order $order): bool
    {
        return DB::transaction(function () use ($order): bool {
            /** @var Order $locked */
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();
            if ($locked->status !== 'paid') {
                return false; // only settled orders can be refunded
            }

            $locked->update(['status' => 'refunded', 'refunded_at' => now()]);
            $locked->tickets()->whereIn('status', ['valid', 'checked_in'])->update(['status' => 'refunded']);
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
        do {
            $ref = 'DRSVP-'.strtoupper(Str::random(6));
        } while (Order::where('reference', $ref)->exists());

        return $ref;
    }
}
