<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Models\Payout;
use App\Services\PayoutService;
use Illuminate\Http\Request;

class PayoutController extends Controller
{
    public function __construct(private readonly PayoutService $payouts) {}

    public function index(Request $request)
    {
        $user = $request->user();

        return inertia('host/payouts', [
            'balance' => $this->payouts->balanceFor($user),
            'payouts' => Payout::where('user_id', $user->id)->latest()->get()->map(fn (Payout $p) => [
                'reference' => $p->reference,
                'amount' => (float) $p->amount,
                'currency' => $p->currency,
                'status' => $p->status,
                'requested_at' => optional($p->requested_at)->format('j M Y'),
                'paid_at' => optional($p->paid_at)->format('j M Y'),
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $this->payouts->request($request->user());

        return back()->with('flash_success', 'Payout requested — we’ll process it shortly.');
    }
}
