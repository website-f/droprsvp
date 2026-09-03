<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\RefundRequest;
use Illuminate\Http\Request;

/**
 * Platform-wide refund oversight + reconciliation — every refund request across
 * all organizers, with headline totals so the superadmin can reconcile refunds
 * against the finance ledger.
 */
class RefundController extends Controller
{
    public function index(Request $request)
    {
        $status = in_array($request->query('status'), ['pending', 'approved', 'declined'], true) ? $request->query('status') : '';

        $requests = RefundRequest::query()
            ->with(['order.event:id,title,slug,user_id', 'order.event.user:id,name', 'requester:id,name'])
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->orderByRaw("case status when 'pending' then 0 else 1 end")
            ->latest()
            ->paginate(25)
            ->withQueryString()
            ->through(fn (RefundRequest $r) => [
                'id' => $r->id,
                'status' => $r->status,
                'amount' => (float) $r->amount,
                'approved_amount' => $r->approved_amount !== null ? (float) $r->approved_amount : null,
                'reference' => $r->order?->reference,
                'event' => $r->order?->event?->title,
                'organizer' => $r->order?->event?->user?->name,
                'requester' => $r->requester?->name ?? 'Guest',
                'when' => optional($r->created_at)->format('j M Y'),
                'decided' => optional($r->decided_at)->format('j M Y'),
            ]);

        return inertia('admin/refunds', [
            'requests' => $requests,
            'filters' => ['status' => $status],
            'stats' => [
                'pending' => RefundRequest::where('status', 'pending')->count(),
                'approved' => RefundRequest::where('status', 'approved')->count(),
                'declined' => RefundRequest::where('status', 'declined')->count(),
                'refunded_total' => round((float) Order::sum('refunded_amount'), 2),
            ],
        ]);
    }
}
