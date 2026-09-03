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

    /** Refund a paid order — the remaining refundable balance, via the gateway. */
    public function refund(Request $request, Event $event, Order $order, PaymentGateway $gateway, CheckoutService $checkout)
    {
        $this->authorize('update', $event);
        abort_unless($order->event_id === $event->id, 404);

        // null amount = the full remaining balance (never the gross total, so a prior
        // partial refund isn't double-charged). Gateway + release run atomically.
        $result = $checkout->refund($order, null, $gateway);

        if (! $result['ok']) {
            return back()->with('flash_error', $result['reason'] === 'gateway'
                ? 'The payment gateway rejected the refund.'
                : 'This order can no longer be refunded.');
        }

        return back()->with('flash_success', "Refunded {$order->reference}.");
    }
}
