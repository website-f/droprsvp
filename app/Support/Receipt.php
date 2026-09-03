<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Payout;

/**
 * Builds a normalized receipt/invoice payload from an order (buyer's purchase,
 * branded with the organizer) or a payout (DropRSVP → organizer). The same shape
 * drives the on-screen receipt page, so both render identically.
 */
class Receipt
{
    /** A buyer's purchase receipt, headed by the event's organizer. */
    public static function forOrder(Order $order): array
    {
        $order->loadMissing(['event.user.organizerProfile', 'items']);
        $event = $order->event;
        $organizer = $event?->user;
        $profile = $organizer?->organizerProfile;

        return [
            'kind' => 'order',
            'title' => 'Receipt',
            'number' => $order->reference,
            'date' => optional($order->paid_at ?? $order->created_at)->format('j M Y'),
            'status' => $order->status,
            'seller' => [
                'name' => $profile?->business_name ?: ($organizer?->name ?? config('app.name')),
                'detail' => $organizer?->email,
                'logo' => $organizer?->avatar ?: $profile?->poster,
                'address' => $profile?->business_address,
                'tax_number' => $profile?->tax_number,
            ],
            'party_label' => 'Billed to',
            'party' => [
                'name' => $order->buyer_name ?: 'Guest',
                'detail' => $order->buyer_email,
            ],
            'context' => $event?->title,
            'items' => $order->items->map(fn ($i) => [
                'description' => $i->name,
                'qty' => (int) $i->quantity,
                'unit' => (float) $i->unit_price,
                'total' => (float) $i->line_total,
            ])->values()->all(),
            'subtotal' => (float) $order->subtotal,
            'tax' => (float) $order->tax,
            'total' => (float) $order->total,
            'currency' => $order->currency,
        ];
    }

    /** An organizer's payout receipt, issued by DropRSVP. */
    public static function forPayout(Payout $payout): array
    {
        $payout->loadMissing('user');
        $vendor = $payout->user;

        return [
            'kind' => 'payout',
            'title' => 'Payout receipt',
            'number' => $payout->reference,
            'date' => optional($payout->paid_at ?? $payout->created_at)->format('j M Y'),
            'status' => $payout->status,
            'seller' => [
                'name' => config('app.name'),
                'detail' => 'Organizer payouts',
                'logo' => null,
            ],
            'party_label' => 'Paid to',
            'party' => [
                'name' => $vendor?->name,
                'detail' => $vendor?->email,
            ],
            'context' => null,
            'items' => [[
                'description' => 'Organizer payout'.($payout->method ? ' · '.$payout->method : ''),
                'qty' => 1,
                'unit' => (float) $payout->amount,
                'total' => (float) $payout->amount,
            ]],
            'subtotal' => (float) $payout->amount,
            'tax' => 0.0,
            'total' => (float) $payout->amount,
            'currency' => $payout->currency,
        ];
    }
}
