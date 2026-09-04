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

        // Per-event: the organizer's ticket revenue (total − fees), net of refunds.
        // Buyers pay the platform fee separately, so it isn't deducted here.
        $events = Event::where('user_id', $user->id)
            ->withCount(['tickets as sold' => fn ($q) => $q->whereIn('status', ['valid', 'checked_in'])])
            ->withSum(['orders as net' => fn ($q) => $q->where('status', 'paid')], \DB::raw('total - fees - refunded_amount'))
            ->withSum(['orders as buyer_fees' => fn ($q) => $q->whereIn('status', ['paid', 'refunded'])], 'fees')
            ->get()
            ->map(function (Event $e) {
                $net = round((float) ($e->net ?? 0), 2);

                return [
                    'slug' => $e->slug,
                    'title' => $e->title,
                    'status' => $e->status,
                    'sold' => (int) $e->sold,
                    'gross' => $net,
                    'fee' => round((float) ($e->buyer_fees ?? 0), 2), // buyer-paid, informational
                    'net' => $net,
                ];
            })
            ->sortByDesc('net')->values();

        return inertia('host/finance', [
            'balance' => $balance,
            'feeLabel' => PlatformFee::label(),
            'events' => $events,
        ]);
    }
}
