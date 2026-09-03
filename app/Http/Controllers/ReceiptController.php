<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Payout;
use App\Support\Receipt;
use App\Support\ReceiptTemplate;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class ReceiptController extends Controller
{
    /** A buyer's order receipt/invoice (owner or superadmin). */
    public function order(Request $request, Order $order)
    {
        abort_unless($this->canViewOrder($order, $request->user()), 403);
        abort_if($order->status === 'pending', 404);

        return inertia('receipts/show', [
            'receipt' => Receipt::forOrder($order),
            'pdfUrl' => route('account.orders.receipt.pdf', $order),
        ]);
    }

    /** Download the order receipt as a PDF. */
    public function orderPdf(Request $request, Order $order)
    {
        abort_unless($this->canViewOrder($order, $request->user()), 403);
        abort_if($order->status === 'pending', 404);

        return $this->pdf(Receipt::forOrder($order));
    }

    /** An organizer's payout receipt (owner or superadmin). */
    public function payout(Request $request, Payout $payout)
    {
        abort_unless($payout->user_id === $request->user()->id || $request->user()->hasRole('superadmin'), 403);

        return inertia('receipts/show', [
            'receipt' => Receipt::forPayout($payout),
            'pdfUrl' => route('account.payouts.receipt.pdf', $payout),
        ]);
    }

    /** Download the payout receipt as a PDF. */
    public function payoutPdf(Request $request, Payout $payout)
    {
        abort_unless($payout->user_id === $request->user()->id || $request->user()->hasRole('superadmin'), 403);

        return $this->pdf(Receipt::forPayout($payout));
    }

    private function pdf(array $receipt)
    {
        return Pdf::loadView('receipts.pdf', ['receipt' => $receipt, 'style' => ReceiptTemplate::resolved()])
            ->download("receipt-{$receipt['number']}.pdf");
    }

    private function ownsOrder(Order $order, $user): bool
    {
        return $order->user_id === $user->id
            || ($order->buyer_email && strcasecmp((string) $order->buyer_email, (string) $user->email) === 0);
    }

    /** The buyer, the event's organizer, or a superadmin may view an order's invoice. */
    private function canViewOrder(Order $order, $user): bool
    {
        return $this->ownsOrder($order, $user)
            || $user->hasRole('superadmin')
            || $order->event?->user_id === $user->id;
    }
}
