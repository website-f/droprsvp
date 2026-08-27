<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payout;
use App\Services\PayoutService;
use Illuminate\Http\Request;

class PayoutController extends Controller
{
    public function __construct(private readonly PayoutService $payouts) {}

    /** Superadmin — all payout requests across organizers (pending first). */
    public function index()
    {
        return inertia('admin/payouts/index', [
            'payouts' => Payout::with('user:id,name,email')
                ->orderByRaw("status = 'pending' desc")
                ->latest()
                ->get()
                ->map(fn (Payout $p) => [
                    'reference' => $p->reference,
                    'organizer' => $p->user?->name,
                    'email' => $p->user?->email,
                    'amount' => (float) $p->amount,
                    'currency' => $p->currency,
                    'status' => $p->status,
                    'requested_at' => optional($p->requested_at)->format('j M Y'),
                    'paid_at' => optional($p->paid_at)->format('j M Y'),
                ]),
        ]);
    }

    public function markPaid(Request $request, Payout $payout)
    {
        $data = $request->validate(['method' => ['nullable', 'string', 'max:60'], 'note' => ['nullable', 'string', 'max:255']]);
        $this->payouts->markPaid($payout, $data['method'] ?? null, $data['note'] ?? null);

        return back()->with('flash_success', "Marked {$payout->reference} paid.");
    }
}
