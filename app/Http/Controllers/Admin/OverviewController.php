<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Order;
use App\Models\Payout;
use App\Models\Ticket;
use App\Models\User;
use App\Support\PlatformFee;

class OverviewController extends Controller
{
    /** Superadmin platform overview + editable platform fee. */
    public function index()
    {
        $paid = Order::where('status', 'paid');
        $gross = (float) (clone $paid)->sum('total');
        $paidOrders = (clone $paid)->count();

        return inertia('admin/overview', [
            'fee_percent' => PlatformFee::percent(),
            'fee_label' => PlatformFee::label(),
            'boost_revenue' => (float) \App\Models\Promotion::where('status', 'paid')->sum('amount'),
            'stats' => [
                'organizers' => User::has('events')->count(),
                'events' => Event::count(),
                'published' => Event::where('status', 'published')->count(),
                'tickets_sold' => Ticket::whereIn('status', ['valid', 'checked_in'])->count(),
                'gross' => $gross,
                'platform_fees' => PlatformFee::on($gross, $paidOrders),
                'pending_payouts' => (float) Payout::where('status', 'pending')->sum('amount'),
                'paid_out' => (float) Payout::where('status', 'paid')->sum('amount'),
            ],
        ]);
    }
}
