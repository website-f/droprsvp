<?php

namespace App\Services;

use App\Mail\TicketsIssued;
use App\Models\Event;
use App\Models\Order;
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
    public function start(Event $event, array $items, ?int $userId = null): Order
    {
        $wanted = collect($items)
            ->map(fn ($i) => ['ticket_type_id' => (int) $i['ticket_type_id'], 'quantity' => (int) $i['quantity']])
            ->filter(fn ($i) => $i['quantity'] > 0)
            ->values();

        if ($wanted->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Select at least one ticket.']);
        }

        return DB::transaction(function () use ($event, $wanted, $userId) {
            $subtotal = 0.0;
            $currency = config('services.hitpay.currency', 'MYR');
            $lines = [];

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

            $order = Order::create([
                'reference' => $this->uniqueReference(),
                'user_id' => $userId,
                'event_id' => $event->id,
                'status' => 'pending',
                'subtotal' => $subtotal,
                'total' => $subtotal,
                'currency' => $currency,
            ]);
            $order->items()->createMany($lines);

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
                        'event_id' => $locked->event_id,
                        'attendee_name' => $locked->buyer_name,
                        'attendee_email' => $locked->buyer_email,
                        'status' => 'valid',
                    ]);
                }
            }

            return true;
        });

        // Email the tickets exactly once, after settlement (outside the transaction).
        if ($newlyPaid && $order->fresh()->buyer_email) {
            $order->load(['event', 'tickets']);
            Mail::to($order->buyer_email)->send(new TicketsIssued($order));
        }
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
            foreach ($locked->items as $item) {
                if ($item->ticket_type_id) {
                    TicketType::whereKey($item->ticket_type_id)->update([
                        'sold' => DB::raw('GREATEST(0, sold - '.(int) $item->quantity.')'),
                    ]);
                }
            }
            $locked->update(['status' => 'cancelled']);
        });
    }

    private function uniqueReference(): string
    {
        do {
            $ref = 'DRSVP-'.strtoupper(Str::random(6));
        } while (Order::where('reference', $ref)->exists());

        return $ref;
    }
}
