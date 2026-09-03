<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\Event;
use App\Models\RefundRequest;
use App\Services\CheckoutService;
use App\Services\Payments\PaymentGateway;
use Illuminate\Http\Request;

/**
 * The organizer's refund queue — buyers' refund requests on their events, which
 * they can approve (full or partial, via the gateway) or decline.
 */
class RefundController extends Controller
{
    public function index(Request $request)
    {
        $eventIds = Event::where('user_id', $request->user()->id)->pluck('id');

        $requests = RefundRequest::whereHas('order', fn ($q) => $q->whereIn('event_id', $eventIds))
            ->with(['order.event:id,title,slug', 'requester:id,name'])
            ->orderByRaw("case status when 'pending' then 0 else 1 end")
            ->latest()
            ->paginate(20)
            ->through(fn (RefundRequest $r) => $this->row($r));

        return inertia('host/refunds', [
            'requests' => $requests,
            'pending' => RefundRequest::whereHas('order', fn ($q) => $q->whereIn('event_id', $eventIds))->where('status', 'pending')->count(),
        ]);
    }

    /** Approve a refund request — refund $amount (≤ what's still refundable) via the gateway. */
    public function approve(Request $request, RefundRequest $refundRequest, PaymentGateway $gateway, CheckoutService $checkout)
    {
        $order = $this->authorized($request, $refundRequest);
        abort_unless($refundRequest->status === 'pending', 422);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:'.$order->remainingRefundable()],
        ]);
        $amount = round((float) $data['amount'], 2);

        if (! $gateway->refund($order, $amount)) {
            return back()->with('flash_error', 'The payment gateway rejected the refund.');
        }

        $result = $checkout->refund($order, $amount);
        if (! $result['ok']) {
            return back()->with('flash_error', 'This order can no longer be refunded.');
        }

        $refundRequest->update([
            'status' => 'approved',
            'approved_amount' => $result['amount'],
            'decided_by' => $request->user()->id,
            'decided_at' => now(),
        ]);

        $this->notifyBuyer($refundRequest, 'Refund approved', 'Your refund of RM'.number_format($result['amount'], 2)." for “{$order->event->title}” was approved.", 'success');

        return back()->with('flash_success', 'Refund approved.');
    }

    public function decline(Request $request, RefundRequest $refundRequest)
    {
        $order = $this->authorized($request, $refundRequest);
        abort_unless($refundRequest->status === 'pending', 422);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        $refundRequest->update([
            'status' => 'declined',
            'decided_by' => $request->user()->id,
            'decided_at' => now(),
            'decision_note' => $data['note'] ?? null,
        ]);

        $this->notifyBuyer($refundRequest, 'Refund declined', "Your refund request for “{$order->event->title}” was declined.".($data['note'] ? ' '.$data['note'] : ''), 'warning');

        return back()->with('flash_success', 'Refund request declined.');
    }

    /** The request must target one of the organizer's own events (or superadmin). */
    private function authorized(Request $request, RefundRequest $refundRequest)
    {
        $order = $refundRequest->order()->with('event')->first();
        abort_unless($order && $order->event, 404);
        abort_unless($order->event->user_id === $request->user()->id || $request->user()->hasRole('superadmin'), 403);

        return $order;
    }

    private function notifyBuyer(RefundRequest $refundRequest, string $title, string $body, string $level): void
    {
        if ($refundRequest->user_id) {
            AppNotification::notify($refundRequest->user_id, [
                'type' => 'refund', 'title' => $title, 'body' => $body, 'url' => '/my/invoices', 'level' => $level,
            ]);
        }
    }

    private function row(RefundRequest $r): array
    {
        return [
            'id' => $r->id,
            'status' => $r->status,
            'amount' => (float) $r->amount,
            'approved_amount' => $r->approved_amount !== null ? (float) $r->approved_amount : null,
            'reason' => $r->reason,
            'decision_note' => $r->decision_note,
            'requester' => $r->requester?->name ?? 'Guest',
            'reference' => $r->order?->reference,
            'remaining' => $r->order ? $r->order->remainingRefundable() : 0,
            'event' => $r->order?->event?->title,
            'when' => $r->created_at?->diffForHumans(),
        ];
    }
}
