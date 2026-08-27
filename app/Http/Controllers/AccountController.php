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
            ->whereIn('status', ['paid', 'refunded'])
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

    /** Re-send the ticket email for one of the buyer's paid orders. */
    public function resend(Request $request, Order $order)
    {
        abort_unless($this->belongsToUser($order, $request->user()), 403);
        abort_unless($order->status === 'paid', 422);

        $email = $order->buyer_email ?: $request->user()->email;
        Mail::to($email)->send(new TicketsIssued($order));

        return back()->with('success', "Tickets re-sent to {$email}.");
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
