<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Admin\SettingsController;
use App\Models\DiscountCode;
use App\Models\Event;
use App\Models\EventDailyStat;
use App\Models\Order;
use App\Services\CheckoutService;
use App\Services\GoogleAnalytics;
use App\Services\Payments\ChipGateway;
use App\Services\Payments\PaymentGateway;
use App\Support\Cities;
use App\Support\CustomFields;
use App\Support\Profile;
use App\Support\Tracking;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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
            // Carried over when the buyer tapped an advertised offer on the
            // event page, so they don't have to retype what they just read.
            'code' => ['nullable', 'string', 'max:60'],
        ]);

        // Intent-to-buy = a click on this event.
        EventDailyStat::bump($event->id, 'clicks');

        $order = $this->checkout->start($event, $data['items'] ?? [], $request->user()?->id, $data['seats'] ?? []);

        // The visitor's GA identity, so a purchase reported later by the server
        // (see GoogleAnalytics) is credited to this visit and its traffic source.
        if ($ga = Tracking::gaIdsFrom($request)) {
            $order->update(['meta' => [...($order->meta ?? []), 'ga' => $ga]]);
        }

        // Bind this order to the browser session that opened it — the reference
        // is a capability, so only this session (or the authenticated owner) may
        // view/pay it.
        $this->rememberOrder($request, $order);

        // Apply a code the buyer brought with them. Never fatal: the order is
        // already created and holding stock, so a code that turns out not to
        // apply (a minimum spend they haven't met, or one that ran out between
        // the page load and the tap) has to leave them on the checkout page
        // with an explanation, not lose the order.
        if ($code = trim((string) ($data['code'] ?? ''))) {
            try {
                $this->checkout->applyDiscount($order, $code);
            } catch (ValidationException $e) {
                return redirect()->route('checkout.show', $order)
                    ->with('flash_warning', $e->validator->errors()->first('code'));
            }
        }

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

        $order->load(['items', 'event', 'discountCode']);

        // Prefill from the signed-in account so they don't retype what we already
        // know — they can still edit any field before paying.
        $user = $request->user();

        // Someone who logged in mid-checkout arrives back here with the order
        // still held by their session but not yet tied to them. Claim it, so the
        // order shows in their account and they keep access if the session rolls.
        if ($user && ! $order->user_id) {
            $order->update(['user_id' => $user->id]);
        }

        return Inertia::render('checkout/show', [
            'order' => $this->orderPayload($order),
            'required' => SettingsController::checkoutRequired(),
            // For the state -> city picker. A free-text city gave us "KL",
            // "kuala lumpur" and "K.L." for one place, and the city is what the
            // browse pages and the organizer's audience breakdown key on.
            'cities' => Cities::all(),
            // Drives the "signed in as…" line vs the "log in" button.
            'account' => $user ? ['name' => $user->name, 'email' => $user->email] : null,
            'loginUrl' => route('checkout.login', $order, false),
            // The organizer's own questions, asked once per ticket in the order.
            'customFields' => CustomFields::forEvent($order->event),
            'ticketCount' => (int) $order->items->sum('quantity'),
            // GA4 funnel entry. Same payload shape as the purchase event, so
            // begin_checkout and purchase are comparable line for line.
            'analytics' => Tracking::checkoutPayload($order),
            'buyer' => $user ? [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'gender' => $user->gender,
                'birth_year' => $user->birth_year,
                'city' => $user->city,
            ] : null,
        ]);
    }

    /**
     * "Already have an account? Log in" — from the checkout page.
     *
     * Parks the checkout URL as the intended destination and hands off to the
     * normal login screen, so Fortify's redirect()->intended() brings them
     * straight back to the same order, now signed in. The order stays reachable
     * because the session keeps its `checkout_orders` entry across login.
     */
    public function login(Request $request, Order $order)
    {
        $this->authorizeOrderAccess($order, $request);

        $request->session()->put('url.intended', route('checkout.show', $order));

        return redirect()->route('login');
    }

    /**
     * Sign the buyer in after a successful checkout, when they asked us to.
     *
     * Deliberately narrow. It only ever signs in an account that was created for
     * THIS order moments ago — `account_created` is set by
     * CheckoutService::provisionBuyerAccount only on the branch that mints a new
     * user. Checking out with an email that already belongs to somebody links
     * the order to them but must never sign the buyer in as them, or knowing an
     * address would be enough to take over an account.
     */
    private function autoLoginIfRequested(Order $order): void
    {
        $meta = $order->meta ?? [];

        if (auth()->check()
            || empty($meta['auto_login'])
            || empty($meta['account_created'])
            || $order->status !== 'paid'
            || ! $order->user) {
            return;
        }

        auth()->login($order->user);
        request()->session()->regenerate();
    }

    /** Apply a promo code to the pending order and recompute the total. */
    public function applyCode(Request $request, Order $order)
    {
        $this->authorizeOrderAccess($order, $request);
        if ($this->paymentLocked($order)) {
            return back()->with('flash_error', 'This order is already checking out — start a new order to change it.');
        }
        $data = $request->validate(['code' => ['required', 'string', 'max:60']]);

        $this->checkout->applyDiscount($order, $data['code']);

        return back()->with('flash_success', 'Promo code applied.');
    }

    /** Remove the applied promo code from the pending order. */
    public function removeCode(Request $request, Order $order)
    {
        $this->authorizeOrderAccess($order, $request);
        if ($this->paymentLocked($order)) {
            return back()->with('flash_error', 'This order is already checking out — start a new order to change it.');
        }
        $this->checkout->clearDiscount($order);

        return back();
    }

    /**
     * Once a gateway checkout has been created (payment_ref set), the amount the
     * buyer is being charged is fixed — the order total must not change underneath
     * it, or the order could settle at a different price than was actually paid.
     */
    private function paymentLocked(Order $order): bool
    {
        return $order->payment_ref !== null;
    }

    /** Capture buyer details and hand off to the payment gateway (or settle free orders). */
    public function pay(Request $request, Order $order, PaymentGateway $gateway)
    {
        $this->authorizeOrderAccess($order, $request);
        abort_unless($order->status === 'pending', 410);

        // Which fields the superadmin marked required (name + email always are).
        $req = SettingsController::checkoutRequired();
        $need = fn (string $field) => $req[$field] ? 'required' : 'nullable';

        $data = $request->validate([
            'buyer_name' => ['required', 'string', 'max:120'],
            'buyer_email' => ['required', 'email', 'max:180'],
            'buyer_phone' => [$need('phone'), 'string', 'max:40'],
            // Demographics — power the organizer's audience analytics.
            'buyer_gender' => [$need('gender'), 'in:female,male,other,na'],
            // Birth year rather than a band; the band is derived below so the
            // organizer's audience analytics are unaffected.
            'buyer_birth_year' => [$need('age_band'), 'integer', 'min:'.Profile::EARLIEST_BIRTH_YEAR, 'max:'.date('Y')],
            'buyer_city' => [$need('city'), 'string', 'max:80'],
            'buyer_source' => [$need('source'), 'in:instagram,facebook,tiktok,friend,search,email,other'],
            // Free-text notes / remarks for the organizer (dietary needs, questions…).
            'notes' => [$need('notes'), 'string', 'max:1000'],
            // The organizer's own questions, one answer set per ticket. Shape is
            // checked here; the values are validated against the event's field
            // definitions by CustomFields::normalise below, which is the only
            // thing that knows what the options actually are.
            'custom_answers' => ['array', 'max:100'],
            'custom_answers.*' => ['array'],
            // "Keep me signed in" — honoured only when checkout creates a brand
            // new account for this buyer; see autoLoginIfRequested().
            'auto_login' => ['boolean'],
            // Consent to use their details for the RSVP + updates.
            'consent' => ['accepted'],
        ], ['consent.accepted' => 'Please agree to the terms to continue.']);
        unset($data['consent']);

        // Answers are captured per TICKET, in the order tickets will be issued —
        // markPaid() walks the order items and their quantities in exactly this
        // sequence, which is what lets each answer set land on the right ticket.
        [$answers, $answerErrors] = CustomFields::normalise(
            $order->event,
            $data['custom_answers'] ?? [],
            (int) $order->items->sum('quantity'),
        );

        if ($answerErrors) {
            throw ValidationException::withMessages($answerErrors);
        }

        $data['buyer_age_band'] = Profile::bandFor(
            isset($data['buyer_birth_year']) ? (int) $data['buyer_birth_year'] : null,
        );

        $data['custom_answers'] = $answers;

        // Stash the preference on the order: the sign-in happens after the
        // gateway returns, which is a different request entirely.
        $autoLogin = (bool) ($data['auto_login'] ?? false);
        unset($data['auto_login']);
        $data['meta'] = [...($order->meta ?? []), 'auto_login' => $autoLogin];
        // Refreshed here too: the GA cookies may only exist by now (the tag
        // loads asynchronously, and a session can start mid-checkout).
        if ($ga = Tracking::gaIdsFrom($request)) {
            $data['meta']['ga'] = $ga;
        }

        $order->update($data);

        // Re-validate any applied promo code at pay time — it may have expired, been
        // deactivated, or hit its redemption limit since it was applied (stale cart).
        if ($order->discount_code_id) {
            $code = DiscountCode::find($order->discount_code_id);
            if (! $code || $code->rejectionReason((float) $order->subtotal) !== null) {
                $this->checkout->clearDiscount($order);

                return back()->with('flash_error', 'Your promo code is no longer valid — your total has been updated.');
            }
        }

        // Free order → settle immediately, no gateway.
        if ((float) $order->total <= 0) {
            $this->checkout->markPaid($order);
            $this->autoLoginIfRequested($order->fresh());

            return redirect()->route('checkout.confirmation', $order);
        }

        // Hand off to the payment gateway. If it's unreachable or errors, don't blow
        // up with a 500 mid-checkout — send the buyer back with a clear, retryable
        // message (surfaced as a toast by FlashWatcher).
        try {
            $redirectUrl = $gateway->createCheckout($order);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('flash_error', 'We couldn’t reach the payment gateway just now. Please try again in a moment.');
        }

        return Inertia::location($redirectUrl);
    }

    /** The fake gateway's "payment page" — instantly settles, then confirms. DEV ONLY. */
    public function fake(Request $request, Order $order)
    {
        // Never a real "settle for free" backdoor in production.
        abort_if(app()->isProduction(), 404);
        $this->authorizeOrderAccess($order, $request);

        if ($order->status === 'pending') {
            // A made-up instrument, so dev and test data render through the same
            // path as a real FPX payment instead of leaving the column blank.
            $this->checkout->markPaid($order, $order->payment_ref, ['method' => 'fpx', 'brand' => 'maybank2u']);
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
        if ($order && $order->status === 'pending' && $gateway instanceof ChipGateway && $gateway->isPaid($order)) {
            $this->checkout->markPaid($order, $order->payment_ref, $gateway->paymentDetails($order->payment_ref));
        }

        if (! $order) {
            return redirect()->route('home')
                ->with('warning', 'We couldn’t match that payment to an order. If you were charged, contact us with your payment reference and we’ll sort it out.');
        }

        // The account is provisioned by markPaid, which may have run here or in
        // the webhook — either way this is the first request back in the buyer's
        // own browser, so it is the only place a session can be started for them.
        $this->autoLoginIfRequested($order->fresh()->load('user'));

        return redirect()->route('checkout.confirmation', $order);
    }

    /** Order confirmation with the issued tickets. */
    public function confirmation(Request $request, Order $order, PaymentGateway $gateway)
    {
        // Confirmation exposes buyer PII + the tickets' QR tokens, so it's gated
        // to the authenticated owner/organizer or the session that checked out.
        $this->authorizeOrderAccess($order, $request);

        // Still pending? Ask CHIP directly rather than wait for the webhook. The
        // gateway often sends the buyer back a few seconds before it settles;
        // this page then polls, and each poll reconciles here. Only for recent
        // orders, so an old abandoned link never costs an API call per visit.
        if ($order->status === 'pending' && $order->payment_ref
            && $order->created_at?->gt(now()->subHours(2))
            && $gateway instanceof ChipGateway && $gateway->isPaid($order)) {
            $this->checkout->markPaid($order, $order->payment_ref, $gateway->paymentDetails($order->payment_ref));
            $order->refresh();
        }

        $order->load(['items', 'event', 'tickets']);

        return Inertia::render('checkout/confirmation', [
            'order' => $this->orderPayload($order, withTickets: true),
            // The GA4 `purchase` event. Nothing ever sent one, which is why the
            // property reported RM0 revenue against real ticket sales.
            // Only while GA has not had this sale yet. The page still has to win
            // a claim before sending it (see analyticsClaim), so a refresh, a
            // second tab or the server sync can never report it twice.
            'analytics' => GoogleAnalytics::browserMaySend($order) ? Tracking::purchasePayload($order) : null,
            'pending' => $order->status === 'pending',
        ]);
    }

    /**
     * The confirmation page asks to report this sale to GA. Only one asker
     * ever wins; everyone else is told not to send.
     */
    public function analyticsClaim(Request $request, Order $order, GoogleAnalytics $ga)
    {
        $this->authorizeOrderAccess($order, $request);

        return response()->json(['send' => $ga->claim($order)]);
    }

    /** GA dispatched the page's purchase hit: mark the sale reported. */
    public function analyticsAck(Request $request, Order $order, GoogleAnalytics $ga)
    {
        $this->authorizeOrderAccess($order, $request);
        $ga->ack($order);

        return response()->noContent();
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
        $event = $order->event; // null if the event was since deleted/archived

        return array_filter([
            'reference' => $order->reference,
            'status' => $order->status,
            'currency' => $order->currency,
            'subtotal' => (float) $order->subtotal,
            'discount' => (float) $order->discount,
            'discount_code' => $order->discount_code_id ? $order->discountCode?->code : null,
            // The platform commission is the organizer's cost, not a line on
            // the buyer's bill — see Receipt::forOrder. Nothing renders it, but
            // it was still being serialised into the page props where anyone
            // could read it, so it is not sent at all.
            'tax' => (float) $order->tax,
            'total' => (float) $order->total,
            'buyer_name' => $order->buyer_name,
            'buyer_email' => $order->buyer_email,
            'event' => [
                'title' => $event?->title ?? 'Event no longer available',
                'slug' => $event?->slug,
                'when' => $event?->starts_at?->setTimezone($event->timezone)->format('D, j M Y · g:i A'),
                'venue_name' => $event?->venue_name,
                'is_online' => (bool) $event?->is_online,
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
