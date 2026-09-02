<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Services\MembershipService;
use App\Services\Payments\ChipGateway;
use App\Services\Payments\PaymentGateway;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MembershipController extends Controller
{
    public function __construct(private readonly MembershipService $membership) {}

    /** The premium benefits + subscribe page. */
    public function show(Request $request)
    {
        $user = $request->user();

        // Superadmins already have full access and never subscribe.
        if (! $user->canSubscribeToPremium()) {
            return redirect()->route('dashboard')->with('success', 'Superadmins already have full access — no membership needed.');
        }

        return Inertia::render('premium', $this->payload($user));
    }

    /** @return array<string, mixed> */
    private function payload($user, ?string $result = null): array
    {
        return [
            'price' => $this->membership->price(),
            'days' => $this->membership->days(),
            'is_premium' => $user->isPremium(),
            'premium_until' => $user->isPremium() ? $user->premium_until->toIso8601String() : null,
            'result' => $result,
        ];
    }

    /** Purchase premium (settles instantly in dev; redirects to CHIP in production). */
    public function subscribe(Request $request, PaymentGateway $gateway)
    {
        abort_unless($request->user()->canSubscribeToPremium(), 403);

        $url = $this->membership->start($request->user(), $gateway);

        if ($url) {
            return Inertia::location($url);
        }

        return redirect()->route('premium')->with('success', 'Welcome to Premium! 🎉');
    }

    /**
     * CHIP returns the buyer here after paying. We reconcile synchronously (the
     * webhook may be delayed or blocked) by re-confirming the latest pending
     * subscription with the gateway, then render a success/processing page.
     */
    public function return(Request $request, PaymentGateway $gateway)
    {
        $user = $request->user();
        $sub = Subscription::where('user_id', $user->id)->latest()->first();

        if ($sub && $sub->status !== 'paid' && $gateway instanceof ChipGateway && $gateway->purchaseIsPaid($sub->payment_ref)) {
            $this->membership->settle($sub);
            $user->refresh();
        }

        return Inertia::render('premium', $this->payload($user, $user->isPremium() ? 'paid' : 'processing'));
    }
}
