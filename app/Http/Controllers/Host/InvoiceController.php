<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Order;
use App\Models\Payout;
use App\Support\Dates;
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
            'requested_at' => Dates::display($p->requested_at, 'j M Y'),
            'paid_at' => Dates::display($p->paid_at, 'j M Y'),
        ]);

        $events = Event::whereIn('user_id', $request->user()->manageableOwnerIds())
            ->withCount(['orders as invoices_count' => fn ($q) => $q->whereIn('status', ['paid', 'refunded'])])
            ->withSum(['orders as revenue' => fn ($q) => $q->where('status', 'paid')], \DB::raw('total - fees - refunded_amount'))
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
            // WHICH ticket was bought, not just how many. An event with a
            // "Session A" and a "Session B" showed an amount and a count here,
            // so there was no way to tell from the revenue page which session
            // the money was for. Eager-loaded: one query for the page, not one
            // per invoice.
            ->with('items:id,order_id,name,quantity,unit_price')
            ->latest('paid_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Order $o) => [
                'reference' => $o->reference,
                'buyer' => $o->buyer_name ?: 'Guest',
                'email' => $o->buyer_email,
                'tickets' => $o->tickets_count,
                // The name is the snapshot taken at purchase, so renaming a
                // ticket type later never rewrites what an old invoice says.
                'types' => $o->items->map(fn ($i) => [
                    'name' => $i->name,
                    'quantity' => (int) $i->quantity,
                    'unit_price' => (float) $i->unit_price,
                ])->values(),
                'total' => (float) $o->total,
                'currency' => $o->currency,
                'status' => $o->status,
                'date' => $o->paid_at?->setTimezone($event->timezone)->format('j M Y, g:i A'),
            ]);

        // Revenue split by ticket type, so the page answers "which session sold?"
        // at a glance rather than only per invoice. Refunds are netted off the
        // order, not the line, so this is gross per type — labelled as such.
        $byType = $event->orders()
            ->where('orders.status', 'paid')
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->groupBy('order_items.name')
            ->orderByDesc('gross')
            ->get([
                'order_items.name',
                \DB::raw('SUM(order_items.quantity) as sold'),
                \DB::raw('SUM(order_items.line_total) as gross'),
            ])
            ->map(fn ($row) => [
                'name' => $row->name,
                'sold' => (int) $row->sold,
                'gross' => round((float) $row->gross, 2),
            ]);

        $gross = (float) $event->orders()->where('status', 'paid')->sum(\DB::raw('total - fees - refunded_amount'));

        return inertia('host/invoices/event', [
            'event' => ['slug' => $event->slug, 'title' => $event->title, 'gross' => round($gross, 2)],
            'orders' => $orders,
            'byType' => $byType,
        ]);
    }
}
