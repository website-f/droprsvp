<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\PayoutService;
use App\Support\PlatformFee;
use Illuminate\Http\Request;

/**
 * The organizer's money overview — gross revenue, the platform fee, net
 * earnings, and what's available vs. held for payout, plus a per-event
 * breakdown so the organizer can tally their numbers against the admin side.
 */
class FinanceController extends Controller
{
    public function __construct(private readonly PayoutService $payouts) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $balance = $this->payouts->balanceFor($user);

        // Per-event: gross paid revenue, the fee on it, and the resulting net.
        $events = Event::where('user_id', $user->id)
            ->withCount(['tickets as sold' => fn ($q) => $q->whereIn('status', ['valid', 'checked_in'])])
            ->withCount(['orders as paid_orders' => fn ($q) => $q->where('status', 'paid')])
            ->withSum(['orders as gross' => fn ($q) => $q->where('status', 'paid')], 'total')
            ->get()
            ->map(function (Event $e) {
                $gross = round((float) ($e->gross ?? 0), 2);
                $fee = PlatformFee::on($gross, (int) $e->paid_orders);

                return [
                    'slug' => $e->slug,
                    'title' => $e->title,
                    'status' => $e->status,
                    'sold' => (int) $e->sold,
                    'gross' => $gross,
                    'fee' => $fee,
                    'net' => round($gross - $fee, 2),
                ];
            })
            ->sortByDesc('gross')->values();

        return inertia('host/finance', [
            'balance' => $balance,
            'feeLabel' => PlatformFee::label(),
            'events' => $events,
        ]);
    }
}
