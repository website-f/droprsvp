<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Order;
use App\Services\CheckoutService;
use App\Services\Payments\PaymentGateway;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(Request $request, Event $event)
    {
        $this->authorize('update', $event);

        return inertia('host/events/orders', [
            'event' => ['title' => $event->title, 'slug' => $event->slug],
            'orders' => $event->orders()
                ->whereIn('status', ['paid', 'refunded'])
                ->withCount('tickets')
                ->latest('paid_at')
                ->get()
                ->map(fn (Order $o) => [
                    'reference' => $o->reference,
                    'buyer' => $o->buyer_name,
                    'email' => $o->buyer_email,
                    'tickets' => $o->tickets_count,
                    'total' => (float) $o->total,
                    'currency' => $o->currency,
                    'status' => $o->status,
                    'notes' => $o->notes,
                    'date' => $o->paid_at?->setTimezone($event->timezone)->format('j M Y, g:i A'),
                ]),
        ]);
    }

    /** Refund a paid order (gateway first, then release locally). */
    public function refund(Request $request, Event $event, Order $order, PaymentGateway $gateway, CheckoutService $checkout)
    {
        $this->authorize('update', $event);
        abort_unless($order->event_id === $event->id, 404);

        if ($order->status !== 'paid') {
            return back()->with('flash_error', 'Only paid orders can be refunded.');
        }

        if (! $gateway->refund($order)) {
            return back()->with('flash_error', 'The payment gateway rejected the refund.');
        }

        $checkout->refund($order);

        return back()->with('flash_success', "Refunded {$order->reference}.");
    }
}
