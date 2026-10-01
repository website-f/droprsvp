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

        // Per event, three figures that add up: what buyers paid for tickets
        // (net of refunds), the platform fee taken out of that, and what is left
        // for the organizer. All over the same PAID orders, so Gross − Fee = Net
        // exactly and the row reconciles against the payout balance.
        //
        // This used to report the net figure in the Gross column too, and label
        // the fee "paid by buyers at checkout" — from when buyers paid a booking
        // fee on top. They no longer do: PayoutService settles total − fees, so
        // the fee comes out of the organizer's takings and the page has to say so.
        $events = Event::where('user_id', $user->id)
            ->withCount(['tickets as sold' => fn ($q) => $q->whereIn('status', ['valid', 'checked_in'])])
            ->withSum(['orders as gross' => fn ($q) => $q->where('status', 'paid')], \DB::raw('total - refunded_amount'))
            ->withSum(['orders as platform_fee' => fn ($q) => $q->where('status', 'paid')], 'fees')
            ->get()
            ->map(function (Event $e) {
                $gross = round((float) ($e->gross ?? 0), 2);
                $fee = round((float) ($e->platform_fee ?? 0), 2);

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
            ->sortByDesc('net')->values();

        return inertia('host/finance', [
            'balance' => $balance,
            'feeLabel' => PlatformFee::label($user),
            'events' => $events,
        ]);
    }
}
