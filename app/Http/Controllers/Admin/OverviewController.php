<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Order;
use App\Models\Payout;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\Request;

class OverviewController extends Controller
{
    /** Superadmin platform overview + editable platform fee. */
    public function index()
    {
        $fee = (float) Setting::get('platform_fee_percent', config('droprsvp.platform_fee_percent'));
        $gross = (float) Order::where('status', 'paid')->sum('total');

        return inertia('admin/overview', [
            'fee_percent' => $fee,
            'boost_price' => (float) Setting::get('boost_price', config('droprsvp.boost_price')),
            'boost_days' => (int) Setting::get('boost_days', config('droprsvp.boost_days')),
            'premium_price' => (float) Setting::get('premium_price', config('droprsvp.premium_price')),
            'boost_revenue' => (float) \App\Models\Promotion::where('status', 'paid')->sum('amount'),
            'stats' => [
                'organizers' => User::has('events')->count(),
                'events' => Event::count(),
                'published' => Event::where('status', 'published')->count(),
                'tickets_sold' => Ticket::whereIn('status', ['valid', 'checked_in'])->count(),
                'gross' => $gross,
                'platform_fees' => round($gross * $fee / 100, 2),
                'pending_payouts' => (float) Payout::where('status', 'pending')->sum('amount'),
                'paid_out' => (float) Payout::where('status', 'paid')->sum('amount'),
            ],
        ]);
    }

    public function updateFee(Request $request)
    {
        $data = $request->validate([
            'fee_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'boost_price' => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            'boost_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'premium_price' => ['sometimes', 'numeric', 'min:0', 'max:100000'],
        ]);
        Setting::put('platform_fee_percent', $data['fee_percent']);
        foreach (['boost_price', 'boost_days', 'premium_price'] as $key) {
            if (array_key_exists($key, $data)) {
                Setting::put($key, $data[$key]);
            }
        }

        return back()->with('flash_success', 'Settings updated.');
    }
}
