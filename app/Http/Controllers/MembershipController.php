<?php

namespace App\Http\Controllers;

use App\Services\MembershipService;
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

        return Inertia::render('premium', [
            'price' => $this->membership->price(),
            'days' => $this->membership->days(),
            'is_premium' => $user->isPremium(),
            'premium_until' => $user->isPremium() ? $user->premium_until->toIso8601String() : null,
        ]);
    }

    /** Purchase premium (settles instantly in dev; redirects to HitPay in production). */
    public function subscribe(Request $request, PaymentGateway $gateway)
    {
        $url = $this->membership->start($request->user(), $gateway);

        if ($url) {
            return Inertia::location($url);
        }

        return redirect()->route('premium')->with('success', 'Welcome to Premium! 🎉');
    }

    public function return()
    {
        return redirect()->route('premium')->with('success', 'Payment received — your Premium is being activated.');
    }
}
