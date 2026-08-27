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
        $data = $request->validate(['fee_percent' => ['required', 'numeric', 'min:0', 'max:100']]);
        Setting::put('platform_fee_percent', $data['fee_percent']);

        return back()->with('flash_success', 'Platform fee updated.');
    }
}
