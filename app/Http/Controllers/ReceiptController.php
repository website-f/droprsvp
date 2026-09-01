<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Payout;
use App\Support\Receipt;
use Illuminate\Http\Request;

class ReceiptController extends Controller
{
    /** A buyer's order receipt/invoice (owner or superadmin). */
    public function order(Request $request, Order $order)
    {
        abort_unless($this->ownsOrder($order, $request->user()) || $request->user()->hasRole('superadmin'), 403);
        abort_if($order->status === 'pending', 404);

        return inertia('receipts/show', ['receipt' => Receipt::forOrder($order)]);
    }

    /** An organizer's payout receipt (owner or superadmin). */
    public function payout(Request $request, Payout $payout)
    {
        abort_unless($payout->user_id === $request->user()->id || $request->user()->hasRole('superadmin'), 403);

        return inertia('receipts/show', ['receipt' => Receipt::forPayout($payout)]);
    }

    private function ownsOrder(Order $order, $user): bool
    {
        return $order->user_id === $user->id
            || ($order->buyer_email && $order->buyer_email === $user->email);
    }
}
