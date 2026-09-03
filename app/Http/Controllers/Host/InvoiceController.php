<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Order;
use App\Models\Payout;
use Illuminate\Http\Request;

/**
 * The organizer's invoice hub — distinct from the buyer's "my invoices" (which
 * lists their own purchases). Here an organizer sees the invoices for the money
 * flowing to them: payout invoices, and per-event the attendees' order invoices.
 */
class InvoiceController extends Controller
{
    /** Payout invoices + a paginated list of the organizer's events. */
    public function index(Request $request)
    {
        $userId = $request->user()->id;

        $payouts = Payout::where('user_id', $userId)->latest()->get()->map(fn (Payout $p) => [
            'reference' => $p->reference,
            'amount' => (float) $p->amount,
            'currency' => $p->currency,
            'status' => $p->status,
            'requested_at' => optional($p->requested_at)->format('j M Y'),
            'paid_at' => optional($p->paid_at)->format('j M Y'),
        ]);

        $events = Event::where('user_id', $userId)
            ->withCount(['orders as invoices_count' => fn ($q) => $q->whereIn('status', ['paid', 'refunded'])])
            ->withSum(['orders as revenue' => fn ($q) => $q->where('status', 'paid')], 'total')
            ->orderByRaw('starts_at is null, starts_at desc')
            ->paginate(12)
            ->withQueryString()
            ->through(fn (Event $e) => [
                'slug' => $e->slug,
                'title' => $e->title,
                'status' => $e->status,
                'when' => $e->starts_at?->setTimezone($e->timezone)->format('j M Y'),
                'invoices' => (int) $e->invoices_count,
                'revenue' => round((float) ($e->revenue ?? 0), 2),
            ]);

        return inertia('host/invoices/index', [
            'payouts' => $payouts,
            'events' => $events,
        ]);
    }

    /** All attendee (order) invoices for one of the organizer's events. */
    public function event(Request $request, Event $event)
    {
        $this->authorize('update', $event);

        $orders = $event->orders()
            ->whereIn('status', ['paid', 'refunded'])
            ->withCount('tickets')
            ->latest('paid_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Order $o) => [
                'reference' => $o->reference,
                'buyer' => $o->buyer_name ?: 'Guest',
                'email' => $o->buyer_email,
                'tickets' => $o->tickets_count,
                'total' => (float) $o->total,
                'currency' => $o->currency,
                'status' => $o->status,
                'date' => $o->paid_at?->setTimezone($event->timezone)->format('j M Y, g:i A'),
            ]);

        $gross = (float) $event->orders()->where('status', 'paid')->sum('total');

        return inertia('host/invoices/event', [
            'event' => ['slug' => $event->slug, 'title' => $event->title, 'gross' => round($gross, 2)],
            'orders' => $orders,
        ]);
    }
}
