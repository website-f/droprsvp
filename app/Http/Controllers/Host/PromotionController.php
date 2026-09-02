<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Promotion;
use App\Models\Setting;
use App\Services\Payments\ChipGateway;
use App\Services\Payments\PaymentGateway;
use App\Services\PromotionService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PromotionController extends Controller
{
    public function __construct(private readonly PromotionService $promotions) {}

    /** The "promote your event" page — price, duration, benefits and current status. */
    public function create(Event $event)
    {
        $this->authorize('update', $event);

        return Inertia::render('host/events/promote', $this->payload($event));
    }

    /** @return array<string, mixed> */
    private function payload(Event $event, ?string $result = null): array
    {
        return [
            'event' => [
                'slug' => $event->slug,
                'title' => $event->title,
                'status' => $event->status,
                'boosted_until' => $event->isBoosted() ? $event->boosted_until->toIso8601String() : null,
            ],
            'boost' => [
                'price' => $this->promotions->price(),
                'days' => $this->promotions->days(),
            ],
            // Transparency: what the platform charges on ticket sales.
            'platform_fee_percent' => (float) Setting::get('platform_fee_percent', config('droprsvp.platform_fee_percent')),
            'result' => $result,
        ];
    }

    /** Purchase a boost (settles instantly in dev; redirects to CHIP in production). */
    public function store(Request $request, Event $event, PaymentGateway $gateway)
    {
        $this->authorize('update', $event);

        $url = $this->promotions->start($event, $request->user(), $gateway);

        if ($url) {
            return Inertia::location($url);
        }

        return redirect()->route('host.events.promote', $event)->with('success', 'Your event is now boosted!');
    }

    /**
     * Where CHIP returns the organizer after paying. Reconcile synchronously (in
     * case the webhook is delayed/blocked) by re-confirming the latest pending
     * promotion with the gateway, then render a success/processing page.
     */
    public function return(Request $request, Event $event, PaymentGateway $gateway)
    {
        $this->authorize('update', $event);

        $promo = Promotion::where('event_id', $event->id)->latest()->first();

        if ($promo && $promo->status !== 'paid' && $gateway instanceof ChipGateway && $gateway->purchaseIsPaid($promo->payment_ref)) {
            $this->promotions->settle($promo);
            $event->refresh();
        }

        return Inertia::render('host/events/promote', $this->payload($event, $event->isBoosted() ? 'paid' : 'processing'));
    }
}
