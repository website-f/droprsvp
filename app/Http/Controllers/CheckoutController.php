<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventDailyStat;
use App\Models\Order;
use App\Services\CheckoutService;
use App\Services\Payments\PaymentGateway;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CheckoutController extends Controller
{
    public function __construct(private readonly CheckoutService $checkout) {}

    /** Reserve tickets and open a pending order from the event page selector. */
    public function start(Request $request, Event $event)
    {
        abort_unless($event->status === 'published', 404);

        $data = $request->validate([
            'items' => ['nullable', 'array'],
            'items.*.ticket_type_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:0'],
            'seats' => ['nullable', 'array'],
            'seats.*' => ['integer'],
        ]);

        // Intent-to-buy = a click on this event.
        EventDailyStat::bump($event->id, 'clicks');

        $order = $this->checkout->start($event, $data['items'] ?? [], $request->user()?->id, $data['seats'] ?? []);

        // Bind this order to the browser session that opened it — the reference
        // is a capability, so only this session (or the authenticated owner) may
        // view/pay it.
        $this->rememberOrder($request, $order);

        return redirect()->route('checkout.show', $order);
    }

    /** Checkout page: order summary + buyer details. */
    public function show(Request $request, Order $order)
    {
        $this->authorizeOrderAccess($order, $request);

        if ($order->status === 'paid') {
            return redirect()->route('checkout.confirmation', $order);
        }
        abort_unless($order->status === 'pending', 410); // released / cancelled

        $order->load(['items', 'event']);

        // Prefill from the signed-in account so they don't retype what we already
        // know — they can still edit any field before paying.
        $user = $request->user();

        return Inertia::render('checkout/show', [
            'order' => $this->orderPayload($order),
            'required' => \App\Http\Controllers\Admin\SettingsController::checkoutRequired(),
            'buyer' => $user ? [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'gender' => $user->gender,
                'age_band' => $user->age_band,
                'city' => $user->city,
            ] : null,
        ]);
    }

    /** Capture buyer details and hand off to the payment gateway (or settle free orders). */
    public function pay(Request $request, Order $order, PaymentGateway $gateway)
    {
        $this->authorizeOrderAccess($order, $request);
        abort_unless($order->status === 'pending', 410);

        // Which fields the superadmin marked required (name + email always are).
        $req = \App\Http\Controllers\Admin\SettingsController::checkoutRequired();
        $need = fn (string $field) => $req[$field] ? 'required' : 'nullable';

        $data = $request->validate([
            'buyer_name' => ['required', 'string', 'max:120'],
            'buyer_email' => ['required', 'email', 'max:180'],
            'buyer_phone' => [$need('phone'), 'string', 'max:40'],
            // Demographics — power the organizer's audience analytics.
            'buyer_gender' => [$need('gender'), 'in:female,male,other,na'],
            'buyer_age_band' => [$need('age_band'), 'in:under-18,18-24,25-34,35-44,45-54,55+'],
            'buyer_city' => [$need('city'), 'string', 'max:80'],
            'buyer_source' => [$need('source'), 'in:instagram,facebook,tiktok,friend,search,email,other'],
            // Free-text notes / remarks for the organizer (dietary needs, questions…).
            'notes' => [$need('notes'), 'string', 'max:1000'],
            // Consent to use their details for the RSVP + updates.
            'consent' => ['accepted'],
        ], ['consent.accepted' => 'Please agree to the terms to continue.']);
        unset($data['consent']);
        $order->update($data);

        // Free order → settle immediately, no gateway.
        if ((float) $order->total <= 0) {
            $this->checkout->markPaid($order);

            return redirect()->route('checkout.confirmation', $order);
        }

        return Inertia::location($gateway->createCheckout($order));
    }

    /** The fake gateway's "payment page" — instantly settles, then confirms. DEV ONLY. */
    public function fake(Request $request, Order $order)
    {
        // Never a real "settle for free" backdoor in production.
        abort_if(app()->isProduction(), 404);
        $this->authorizeOrderAccess($order, $request);

        if ($order->status === 'pending') {
            $this->checkout->markPaid($order, $order->payment_ref);
        }

        return redirect()->route('checkout.confirmation', $order);
    }

    /** Where the real gateway redirects the buyer back to. */
    public function return(Request $request, PaymentGateway $gateway)
    {
        $reference = $request->query('reference_number') ?? $request->query('reference');
        $order = $reference ? Order::where('reference', $reference)->first() : null;

        // The webhook is the source of truth, but it can lag the redirect — so if
        // the order is still pending, confirm directly with the gateway.
        if ($order && $order->status === 'pending' && $gateway instanceof \App\Services\Payments\ChipGateway && $gateway->isPaid($order)) {
            $this->checkout->markPaid($order, $order->payment_ref);
        }

        return $order
            ? redirect()->route('checkout.confirmation', $order)
            : redirect()->route('home');
    }

    /** Order confirmation with the issued tickets. */
    public function confirmation(Request $request, Order $order)
    {
        // Confirmation exposes buyer PII + the tickets' QR tokens, so it's gated
        // to the authenticated owner/organizer or the session that checked out.
        $this->authorizeOrderAccess($order, $request);

        $order->load(['items', 'event', 'tickets']);

        return Inertia::render('checkout/confirmation', [
            'order' => $this->orderPayload($order, withTickets: true),
        ]);
    }

    /** Remember an order reference against the current session (capped, deduped). */
    private function rememberOrder(Request $request, Order $order): void
    {
        $refs = (array) $request->session()->get('checkout_orders', []);
        $refs[] = $order->reference;
        $request->session()->put('checkout_orders', array_slice(array_values(array_unique($refs)), -20));
    }

    /**
     * The order reference is a capability. A request may see/act on an order only
     * if it's the authenticated owner (buyer, the event's organizer, or a
     * superadmin) OR the browser session that opened the checkout.
     */
    private function authorizeOrderAccess(Order $order, Request $request): void
    {
        $user = $request->user();
        if ($user && (
            $order->user_id === $user->id
            || ($order->buyer_email && strcasecmp((string) $order->buyer_email, (string) $user->email) === 0)
            || $user->hasRole('superadmin')
            || $order->event?->user_id === $user->id
        )) {
            return;
        }

        $refs = (array) $request->session()->get('checkout_orders', []);
        abort_unless(in_array($order->reference, $refs, true), 403);
    }

    private function orderPayload(Order $order, bool $withTickets = false): array
    {
        return array_filter([
            'reference' => $order->reference,
            'status' => $order->status,
            'currency' => $order->currency,
            'total' => (float) $order->total,
            'buyer_name' => $order->buyer_name,
            'buyer_email' => $order->buyer_email,
            'event' => [
                'title' => $order->event->title,
                'slug' => $order->event->slug,
                'when' => $order->event->starts_at?->setTimezone($order->event->timezone)->format('D, j M Y · g:i A'),
                'venue_name' => $order->event->venue_name,
                'is_online' => $order->event->is_online,
            ],
            'items' => $order->items->map(fn ($i) => [
                'name' => $i->name,
                'quantity' => $i->quantity,
                'unit_price' => (float) $i->unit_price,
                'line_total' => (float) $i->line_total,
            ]),
            'tickets' => $withTickets ? $order->tickets->map(fn ($t) => [
                'qr_token' => $t->qr_token,
                'attendee_name' => $t->attendee_name,
                'status' => $t->status,
            ]) : null,
        ], fn ($v) => $v !== null);
    }
}
