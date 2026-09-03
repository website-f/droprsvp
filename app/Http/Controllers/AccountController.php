<?php

namespace App\Http\Controllers;

use App\Mail\TicketsIssued;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;

class AccountController extends Controller
{
    /** The buyer's ticket wallet — every order they've placed, most recent first. */
    public function tickets(Request $request)
    {
        $user = $request->user();

        $orders = $this->ownedBy($user)
            ->whereIn('status', ['paid', 'refunded', 'cancelled'])
            ->with([
                'event:id,title,slug,timezone,starts_at,venue_name,is_online,cover_image',
                'tickets:id,order_id,ticket_type_id,qr_token,attendee_name,status',
                'tickets.ticketType:id,name',
            ])
            ->latest()
            ->paginate(10)
            ->through(fn (Order $o) => $this->payload($o));

        return Inertia::render('account/tickets', [
            'orders' => $orders,
        ]);
    }

    /** The buyer's invoices — every purchase with a downloadable receipt. */
    public function invoices(Request $request)
    {
        $user = $request->user();

        $orders = $this->ownedBy($user)
            ->whereIn('status', ['paid', 'refunded'])
            ->with('event:id,title,starts_at,timezone,refund_policy')
            ->withCount(['refundRequests as pending_refunds' => fn ($q) => $q->where('status', 'pending')])
            ->latest()
            ->paginate(12)
            ->through(fn (Order $o) => [
                'reference' => $o->reference,
                'event' => $o->event?->title,
                'total' => (float) $o->total,
                'refunded_amount' => (float) $o->refunded_amount,
                'currency' => $o->currency,
                'status' => $o->status,
                'date' => optional($o->paid_at ?? $o->created_at)->format('j M Y'),
                'can_refund' => $o->status === 'paid' && $o->event && $o->event->allowsRefundRequest()
                    && $o->pending_refunds === 0 && $o->remainingRefundable() > 0,
                'refund_pending' => $o->pending_refunds > 0,
            ]);

        return Inertia::render('account/invoices', ['orders' => $orders]);
    }

    /** Re-send the ticket email for one of the buyer's paid orders. */
    public function resend(Request $request, Order $order)
    {
        abort_unless($this->belongsToUser($order, $request->user()), 403);
        abort_unless($order->status === 'paid', 422);

        $email = $order->buyer_email ?: $request->user()->email;
        \App\Support\Mailer::defer($email, new TicketsIssued($order));

        return back()->with('success', "Tickets re-sent to {$email}.");
    }

    /**
     * Buyer cancels a free registration before the event starts. Paid orders can't
     * be cancelled here — they go through the refund flow, which the organizer settles.
     */
    public function cancel(Request $request, Order $order)
    {
        abort_unless($this->belongsToUser($order, $request->user()), 403);
        abort_unless($order->status === 'paid', 422);
        abort_unless((float) $order->total <= 0, 422); // free registrations only

        $order->loadMissing('event');
        // Only before the event starts (an undated event stays cancellable).
        abort_if($order->event && $order->event->starts_at && $order->event->starts_at->isPast(), 422);

        if (! app(\App\Services\CheckoutService::class)->cancelFree($order)) {
            return back()->with('flash_error', 'This registration can no longer be cancelled.');
        }

        // Let the organizer know a seat opened back up.
        if ($order->event && $order->event->user_id) {
            \App\Models\AppNotification::notify($order->event->user_id, [
                'type' => 'order',
                'title' => 'Registration cancelled',
                'body' => "{$request->user()->name} cancelled their registration for “{$order->event->title}” ({$order->reference}).",
                'url' => '/host/events/'.$order->event->slug.'/attendees',
                'level' => 'info',
            ]);
        }

        return back()->with('flash_success', 'Your registration has been cancelled.');
    }

    /** Buyer opens a refund request for a paid order (subject to the event's policy). */
    public function requestRefund(Request $request, Order $order)
    {
        abort_unless($this->belongsToUser($order, $request->user()), 403);
        abort_unless($order->status === 'paid', 422);

        $order->loadMissing('event');
        abort_unless($order->event && $order->event->allowsRefundRequest(), 422);

        if ($order->hasPendingRefund() || $order->remainingRefundable() <= 0) {
            return back()->with('flash_error', 'A refund for this order is already in progress.');
        }

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        \App\Models\RefundRequest::create([
            'order_id' => $order->id,
            'user_id' => $request->user()->id,
            'amount' => $order->remainingRefundable(),
            'reason' => $data['reason'] ?? null,
            'status' => 'pending',
        ]);

        // Ping the event's organizer.
        if ($order->event->user_id) {
            \App\Models\AppNotification::notify($order->event->user_id, [
                'type' => 'refund',
                'title' => 'New refund request',
                'body' => "{$request->user()->name} requested a refund for “{$order->event->title}” ({$order->reference}).",
                'url' => '/host/refunds',
                'level' => 'warning',
            ]);
        }

        return back()->with('flash_success', 'Your refund request has been sent to the organizer.');
    }

    /** Orders tied to the account by id, or placed as a guest with the account's email. */
    private function ownedBy($user)
    {
        return Order::query()->where(function ($q) use ($user) {
            $q->where('user_id', $user->id);
            if ($user->email) {
                $q->orWhere('buyer_email', $user->email);
            }
        });
    }

    private function belongsToUser(Order $order, $user): bool
    {
        return $order->user_id === $user->id
            || ($order->buyer_email && $order->buyer_email === $user->email);
    }

    private function payload(Order $order): array
    {
        $event = $order->event;

        return [
            'reference' => $order->reference,
            'status' => $order->status,
            'total' => (float) $order->total,
            'currency' => $order->currency,
            'placed_on' => optional($order->created_at)->format('j M Y'),
            // Free registrations can be self-cancelled up until the event starts.
            'can_cancel' => $order->status === 'paid' && (float) $order->total <= 0
                && (! $event || ! $event->starts_at || $event->starts_at->isFuture()),
            'event' => $event ? [
                'title' => $event->title,
                'slug' => $event->slug,
                'when' => $event->starts_at
                    ? $event->starts_at->copy()->setTimezone($event->timezone)->format('D, j M Y · g:i A')
                    : null,
                'venue_name' => $event->venue_name,
                'is_online' => (bool) $event->is_online,
                'cover_image' => $event->cover_image,
            ] : null,
            'tickets' => $order->tickets->map(fn ($t) => [
                'qr_token' => $t->qr_token,
                'attendee_name' => $t->attendee_name,
                'type' => $t->ticketType?->name,
                'status' => $t->status,
            ])->all(),
        ];
    }
}
